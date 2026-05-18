<?php

declare(strict_types=1);

namespace App\Command;

use App\Aggregator\AggregatorInterface;
use App\Aggregator\FlagAggregator;
use App\Aggregator\WordlistFormat;
use App\Aggregator\ImageRevealAggregator;
use App\Aggregator\LocationAggregator;
use App\Aggregator\MultipleChoiceAggregator;
use App\Aggregator\SongAggregator;
use App\Aggregator\TrueFalseAggregator;
use App\Aggregator\WordlistReader;
use App\Config\EnvLoader;
use App\Model\Question;
use App\Provider\ApiProvider;
use App\Provider\FileProvider;
use App\Provider\PixabayImageProvider;
use App\Provider\ProviderType;
use App\Provider\QuestionProviderInterface;
use App\Provider\TranslatingProvider;
use App\Provider\WikimediaImageProvider;
use App\Provider\WikipediaProvider;
use App\Translator\DeepLTranslator;
use App\Repository\BlacklistRepository;
use App\Repository\QuestionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'aggregate:content', description: 'Fetch quiz content from external sources')]
final class AggregateContentCommand extends Command
{
    private const DATA_FILE       = __DIR__ . '/../../data/questions.json';
    private const BLACKLIST_FILE  = __DIR__ . '/../../data/reported_questions.json';
    private const IMAGES_DIR     = __DIR__ . '/../../public/images';
    private const AUDIO_DIR      = __DIR__ . '/../../public/audio/songs';
    private const AUDIO_WEB_PATH = '/audio/songs';
    private const PROJECT_DIR    = __DIR__ . '/../..';
    private const DEFAULT_COUNT  = 20;
    private const SUPPORTED_LANGS = ['de', 'en'];
    private const DEFAULT_TRIVIA_CATEGORIES = '9,10,11,12,15,17,18,19,20,21,22,23,26,27,28,30,32';

    protected function configure(): void
    {
        $this
            ->addOption('count', null, InputOption::VALUE_OPTIONAL, 'Questions per category', self::DEFAULT_COUNT)
            ->addOption('categories', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Categories to fetch (default: all)')
            ->addOption('lang', null, InputOption::VALUE_OPTIONAL, 'Language for questions (de or en)', 'de')
            ->addOption('clear-images', null, InputOption::VALUE_NONE, 'Delete all cached images before aggregating')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Show provider debug output (also enabled by -v)')
            ->addOption('random-words', null, InputOption::VALUE_NONE, 'Pick words randomly from WORDLIST_PATH instead of sequentially from the top')
            ->addOption('generate-flags', null, InputOption::VALUE_NONE, 'Include flag_mc aggregation (excluded by default)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $count      = (int) $input->getOption('count');
        $categories = (array) $input->getOption('categories');
        $lang       = (string) $input->getOption('lang');

        if (!in_array($lang, self::SUPPORTED_LANGS, true)) {
            $io->error(sprintf('Language "%s" is not supported. Use: %s', $lang, implode(', ', self::SUPPORTED_LANGS)));
            return Command::FAILURE;
        }

        $providerType = ProviderType::tryFrom(EnvLoader::get('QUIZ_PROVIDER', 'API')) ?? ProviderType::Api;
        $io->writeln(sprintf('Language: <info>%s</info>  Provider: <info>%s</info>', $lang, $providerType->value));

        if ($input->getOption('clear-images')) {
            $this->clearImagesDir($io);
        }

        $isDebug     = $input->getOption('debug') || $output->isVerbose();
        $debugLogger = $isDebug ? fn(string $msg) => $io->writeln("  <comment>[debug] $msg</comment>") : null;

        $client         = HttpClient::create(['timeout' => 30]);
        $provider       = $this->buildProvider($providerType, $client, $lang, $debugLogger);
        $generateFlags  = (bool) $input->getOption('generate-flags');
        $randomWords    = (bool) $input->getOption('random-words');

        $allAggregators = $this->buildAllAggregators($lang, $client, $provider, $debugLogger, $randomWords);
        $aggregators    = $this->filterAggregatorsByCategory($allAggregators, $categories, $generateFlags);
        $repository = new QuestionRepository(self::DATA_FILE);
        $blacklist  = new BlacklistRepository(self::BLACKLIST_FILE);

        foreach ($aggregators as $aggregator) {
            $io->section(sprintf('Fetching: %s', $aggregator->getCategoryId()));
            $this->fetchAndMerge($aggregator, $count, $repository, $blacklist, $io);
        }

        $this->printSummary($io, $repository->load());

        return Command::SUCCESS;
    }

    private function fetchAndMerge(AggregatorInterface $aggregator, int $count, QuestionRepository $repository, BlacklistRepository $blacklist, SymfonyStyle $io): void
    {
        $fetched = $aggregator->fetch($count);
        $io->writeln(sprintf('  → %d questions fetched', count($fetched)));

        $existing      = $repository->load();
        $existingIds   = array_map(fn(Question $question) => $question->id, $existing);
        $blacklistedIds = $blacklist->loadIds();

        $uniqueNew = array_values(array_filter(
            $fetched,
            fn(Question $question) => !in_array($question->id, $existingIds, true)
                && !in_array($question->id, $blacklistedIds, true)
        ));

        $repository->save(array_merge($existing, $uniqueNew));
        $io->writeln(sprintf('  → %d new questions added', count($uniqueNew)));
    }

    private function buildProvider(ProviderType $type, HttpClientInterface $client, string $lang, ?\Closure $debugLogger): QuestionProviderInterface
    {
        $triviaUrl = $lang === 'de'
            ? EnvLoader::get('OPENTRIVIA_URL_DE', 'https://api.opentrivia.de')
            : EnvLoader::get('OPENTRIVIA_URL_EN', 'https://opentdb.com/api.php');

        $provider = match($type) {
            ProviderType::Api => new ApiProvider(
                $client,
                $triviaUrl,
                $this->parseTriviaCategories(),
                $debugLogger,
            ),
            ProviderType::Wikipedia => new WikipediaProvider(
                $client,
                EnvLoader::get('ANTHROPIC_API_KEY'),
                EnvLoader::get('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
                $debugLogger,
            ),
            ProviderType::File => new FileProvider(
                self::PROJECT_DIR . '/' . ltrim(EnvLoader::get('QUESTION_FILE_PATH', 'data/custom_questions.json'), '/')
            ),
        };

        $deeplKey = EnvLoader::get('DEEPL_API_KEY', '');

        if ($type === ProviderType::Api && $lang !== 'en' && $deeplKey !== '') {
            return new TranslatingProvider(
                $provider,
                new DeepLTranslator(
                    $client,
                    $deeplKey,
                    EnvLoader::get('DEEPL_API_URL_FREE', 'https://api-free.deepl.com/v2/translate'),
                    EnvLoader::get('DEEPL_API_URL_PRO', 'https://api.deepl.com/v2/translate'),
                ),
                $lang,
            );
        }

        return $provider;
    }

    /** @return int[] */
    private function parseTriviaCategories(): array
    {
        $raw = EnvLoader::get('OPENTRIVIA_CATEGORIES', self::DEFAULT_TRIVIA_CATEGORIES);

        return array_values(array_filter(
            array_map(fn(string $id) => (int) trim($id), explode(',', $raw))
        ));
    }

    private function buildWordlistReader(): ?WordlistReader
    {
        $raw = EnvLoader::get('WORDLIST_PATH');
        if ($raw === '') {
            return null;
        }

        $path = str_starts_with($raw, '/') ? $raw : self::PROJECT_DIR . '/' . ltrim($raw, '/');

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $format = WordlistFormat::tryFrom(EnvLoader::get('WORDLIST_FORMAT', 'newline')) ?? WordlistFormat::Newline;

        return new WordlistReader($path, $format);
    }

    /** @return AggregatorInterface[] */
    private function buildAllAggregators(
        string $lang,
        HttpClientInterface $client,
        QuestionProviderInterface $provider,
        ?\Closure $debugLogger,
        bool $randomWords,
    ): array {
        $imageProviderType = EnvLoader::get('IMAGE_PROVIDER', 'pixabay');
        $imageProvider = match($imageProviderType) {
            'wikimedia' => new WikimediaImageProvider(
                $client,
                EnvLoader::get('WIKIMEDIA_API_URL', 'https://en.wikipedia.org/w/api.php'),
            ),
            default => new PixabayImageProvider(
                $client,
                EnvLoader::get('PIXABAY_API_KEY', ''),
                EnvLoader::get('PIXABAY_API_URL', 'https://pixabay.com/api/'),
            ),
        };

        $flagScope = EnvLoader::get('FLAG_SCOPE', 'world');

        return [
            'true_false'      => new TrueFalseAggregator($provider, $lang),
            'multiple_choice' => new MultipleChoiceAggregator($provider, $lang),
            'song_guess'      => new SongAggregator(
                $client,
                EnvLoader::get('DEEZER_CHART_URL', 'https://api.deezer.com/chart/0/tracks'),
                self::AUDIO_DIR,
                self::AUDIO_WEB_PATH,
                $lang,
            ),
            'image_reveal'    => new ImageRevealAggregator($imageProvider, $lang, $debugLogger, $this->buildWordlistReader(), $randomWords),
            'location'        => new LocationAggregator(
                $client,
                self::IMAGES_DIR,
                EnvLoader::get('WIKIDATA_SPARQL_URL', 'https://query.wikidata.org/sparql'),
                $lang,
                (int) EnvLoader::get('WIKIDATA_MIN_SITELINKS', '50'),
                (int) EnvLoader::get('WIKIDATA_FETCH_LIMIT', '300'),
                (int) EnvLoader::get('LOCATION_MAX_PER_COUNTRY', '3'),
                $debugLogger,
            ),
            'flag_mc'         => new FlagAggregator(
                $client,
                self::IMAGES_DIR,
                EnvLoader::get('REST_COUNTRIES_BASE_URL', 'https://restcountries.com/v3.1'),
                $lang,
                $flagScope,
                $this->resolveFlagMinPopulation($flagScope),
            ),
        ];
    }

    /**
     * @param AggregatorInterface[] $aggregators
     * @return AggregatorInterface[]
     */
    private function filterAggregatorsByCategory(array $aggregators, array $categories, bool $includeFlags): array
    {
        $filtered = $includeFlags ? $aggregators : array_diff_key($aggregators, ['flag_mc' => null]);

        if (empty($categories)) {
            return $filtered;
        }

        return array_filter(
            $filtered,
            fn(string $key) => in_array($key, $categories, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function resolveFlagMinPopulation(string $scope): int
    {
        if ($scope === 'europe') {
            return (int) EnvLoader::get('FLAG_MIN_POPULATION_EUROPE', '1000000');
        }

        return (int) EnvLoader::get('FLAG_MIN_POPULATION_WORLD', '10000000');
    }

    private function clearImagesDir(SymfonyStyle $io): void
    {
        $files = glob(self::IMAGES_DIR . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $io->writeln(sprintf('  → Deleted %d cached image files', count($files)));
    }

    /** @param Question[] $questions */
    private function printSummary(SymfonyStyle $io, array $questions): void
    {
        $byCategory = [];
        foreach ($questions as $question) {
            $byCategory[$question->category] = ($byCategory[$question->category] ?? 0) + 1;
        }

        $io->success(sprintf('Total: %d questions in questions.json', count($questions)));
        foreach ($byCategory as $category => $count) {
            $io->writeln(sprintf('  %s: %d', $category, $count));
        }
    }
}
