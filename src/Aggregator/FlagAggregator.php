<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Model\QuestionType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FlagAggregator implements AggregatorInterface
{
    private const CATEGORY_ID = 'flag_mc';
    private const QUESTION_TEXTS = [
        'de' => 'Welche Flagge gehört zu: %s?',
        'en' => 'Which flag belongs to: %s?',
    ];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $imagesDir,
        private readonly string $restCountriesBaseUrl,
        private readonly string $lang = 'de',
        private readonly string $scope = 'world',
        private readonly int $minPopulation = 10_000_000,
    ) {}

    public function getCategoryId(): string
    {
        return self::CATEGORY_ID;
    }

    /** @return Question[] */
    public function fetch(int $count): array
    {
        $this->ensureImagesDir();

        $countries = $this->fetchCountries();
        if (count($countries) < 3) {
            return [];
        }

        $available = $this->buildAvailableCountries($countries);
        if (count($available) < 3) {
            return [];
        }

        shuffle($available);
        $selected = array_slice($available, 0, min($count, count($available)));

        return array_values(array_filter(
            array_map(fn(array $country) => $this->buildQuestion($country, $available), $selected)
        ));
    }

    private function fetchCountries(): array
    {
        $url = $this->scope === 'europe'
            ? $this->restCountriesBaseUrl . '/region/europe'
            : $this->restCountriesBaseUrl . '/all';

        try {
            $response = $this->client->request('GET', $url, [
                'query' => ['fields' => 'name,flags,population,translations'],
            ]);
            $data = $response->toArray();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            $data,
            fn(array $country) => ($country['population'] ?? 0) >= $this->minPopulation && !empty($country['flags']['png'])
        ));
    }

    /** @return array<array{name: string, image: string}> */
    private function buildAvailableCountries(array $countries): array
    {
        $available = [];

        foreach ($countries as $country) {
            $name    = $this->resolveCountryName($country);
            $flagUrl = $country['flags']['png'] ?? null;

            if ($name === null || $flagUrl === null) {
                continue;
            }

            $filename  = $this->buildFilename($country['name']['common']);
            $imagePath = $this->imagesDir . '/' . $filename;

            if (!file_exists($imagePath)) {
                if (!$this->downloadImage($flagUrl, $imagePath)) {
                    continue;
                }
                usleep(100_000);
            }

            $available[] = ['name' => $name, 'image' => 'images/' . $filename];
        }

        return $available;
    }

    private function buildQuestion(array $correct, array $all): ?Question
    {
        $distractors = array_values(array_filter($all, fn(array $country) => $country['name'] !== $correct['name']));
        if (count($distractors) < 2) {
            return null;
        }

        shuffle($distractors);
        $options = [
            ['label' => $correct['name'], 'image_path' => $correct['image']],
            ['label' => $distractors[0]['name'], 'image_path' => $distractors[0]['image']],
            ['label' => $distractors[1]['name'], 'image_path' => $distractors[1]['image']],
        ];
        shuffle($options);

        $questionText = sprintf(
            self::QUESTION_TEXTS[$this->lang] ?? self::QUESTION_TEXTS['de'],
            $correct['name']
        );

        return new Question(
            id: md5($correct['name'] . $this->lang),
            category: self::CATEGORY_ID,
            type: QuestionType::FlagMc,
            question: $questionText,
            answer: $correct['name'],
            options: $options,
        );
    }

    private function resolveCountryName(array $country): ?string
    {
        if ($this->lang === 'de') {
            return $country['translations']['deu']['common'] ?? $country['name']['common'] ?? null;
        }

        return $country['name']['common'] ?? null;
    }

    private function downloadImage(string $url, string $path): bool
    {
        try {
            $response = $this->client->request('GET', $url);
            file_put_contents($path, $response->getContent());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildFilename(string $countryName): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $countryName));

        return sprintf('flag-%s.png', trim($slug, '-'));
    }

    private function ensureImagesDir(): void
    {
        if (!is_dir($this->imagesDir)) {
            mkdir($this->imagesDir, 0755, true);
        }
    }
}