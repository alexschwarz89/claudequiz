<?php

declare(strict_types=1);

namespace App\Provider;

use App\Model\Question;
use App\Model\QuestionType;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WikipediaProvider implements QuestionProviderInterface
{
    private const WIKI_API_URL      = 'https://%s.wikipedia.org/w/api.php';
    private const MODEL             = 'claude-haiku-4-5-20251001';
    private const PER_ARTICLE       = 1;
    private const MAX_RETRIES       = 4;
    private const RETRY_FALLBACK_US = 5_000_000; // 5s — Wikimedia minimum when no Retry-After header
    private const FEATURED_CATEGORY = [
        'de' => 'Kategorie:Wikipedia:Exzellent',
        'en' => 'Category:Featured_articles',
    ];

    /** @var array<string, list<string>> */
    private array $titlePool = [];
    private bool $rateLimitExhausted = false;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $anthropicApiKey,
        private readonly string $anthropicApiUrl,
        private readonly array $excludedTopics = [],
        private readonly ?\Closure $debugLogger = null,
    ) {}

    private function debug(string $message): void
    {
        if ($this->debugLogger !== null) {
            ($this->debugLogger)($message);
        }
    }

    /** @return Question[] */
    public function provide(int $count, QuestionFormat $format, string $lang): array
    {
        $questions   = [];
        $attempts    = 0;
        $maxAttempts = (int) ceil($count / self::PER_ARTICLE) * 3;

        while (count($questions) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $article = $this->fetchRandomArticle($lang);

            if ($this->rateLimitExhausted) {
                $this->debug(sprintf('Rate limit exhausted — saving %d questions and stopping', count($questions)));
                break;
            }

            if ($article === null) {
                continue;
            }

            $needed    = $count - count($questions);
            $generated = $this->generateQuestions($article, min(self::PER_ARTICLE, $needed), $format, $lang);
            $questions = array_merge($questions, $generated);
            usleep(1_500_000);
        }

        return array_slice($questions, 0, $count);
    }

    private function fetchRandomArticle(string $lang): ?array
    {
        $title = $this->resolveRandomTitle($lang);
        if ($title === null) {
            return null;
        }

        $article = $this->fetchArticle($lang, $title);

        if ($article !== null && $this->isExcludedByTopic($article['categories'])) {
            $this->debug("Skipped (topic filter): {$title}");
            return null;
        }

        return $article;
    }

    private function resolveRandomTitle(string $lang): ?string
    {
        if (empty($this->titlePool[$lang])) {
            $this->titlePool[$lang] = $this->loadFeaturedTitles($lang);
        }

        if (empty($this->titlePool[$lang])) {
            return null;
        }

        $index = array_rand($this->titlePool[$lang]);
        $title = $this->titlePool[$lang][$index];
        unset($this->titlePool[$lang][$index]);
        $this->debug("Article: {$title}");

        return $title;
    }

    /** @return list<string> */
    private function loadFeaturedTitles(string $lang): array
    {
        $category = self::FEATURED_CATEGORY[$lang] ?? self::FEATURED_CATEGORY['en'];
        $titles   = [];
        $token    = null;

        do {
            [$batch, $token] = $this->fetchTitleBatch($lang, $category, $token);
            $titles = array_merge($titles, $batch);
        } while ($token !== null);

        shuffle($titles);

        $this->debug(sprintf('Loaded %d featured article titles (%s)', count($titles), $lang));

        return $titles;
    }

    /** @return array{list<string>, ?string} */
    private function fetchTitleBatch(string $lang, string $category, ?string $continueToken): array
    {
        $query = [
            'action'      => 'query',
            'list'        => 'categorymembers',
            'cmtitle'     => $category,
            'cmtype'      => 'page',
            'cmnamespace' => 0,
            'cmlimit'     => 500,
            'format'      => 'json',
        ];

        if ($continueToken !== null) {
            $query['cmcontinue'] = $continueToken;
        }

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response   = $this->client->request('GET', sprintf(self::WIKI_API_URL, $lang), ['query' => $query]);
                $statusCode = $response->getStatusCode();

                if ($statusCode === 429 || $statusCode === 503) {
                    if ($attempt === self::MAX_RETRIES) {
                        $this->debug('fetchTitleBatch: rate limit persists — aborting title load');
                        return [[], null];
                    }
                    $delay = $this->resolveRetryDelay($response, self::RETRY_FALLBACK_US);
                    $this->debug(sprintf('fetchTitleBatch: %d — retrying in %ds...', $statusCode, $delay / 1_000_000));
                    usleep($delay);
                    continue;
                }

                $data = $response->toArray();
            } catch (\Throwable $e) {
                $this->debug('fetchTitleBatch failed: ' . $e->getMessage());
                return [[], null];
            }

            $titles = array_column($data['query']['categorymembers'] ?? [], 'title');
            $next   = $data['continue']['cmcontinue'] ?? null;

            return [$titles, $next];
        }

        return [[], null];
    }

    private function fetchArticle(string $lang, string $title): ?array
    {
        $delays = [2_000_000, 4_000_000, 8_000_000, 16_000_000];
        $data   = null;

        for ($attempt = 0; $attempt <= count($delays); $attempt++) {
            try {
                $response   = $this->client->request('GET', sprintf(self::WIKI_API_URL, $lang), [
                    'query' => [
                        'action'      => 'query',
                        'prop'        => 'extracts|categories',
                        'exintro'     => true,
                        'explaintext' => true,
                        'exsentences' => 8,
                        'cllimit'     => 50,
                        'clshow'      => '!hidden',
                        'titles'      => $title,
                        'format'      => 'json',
                    ],
                ]);
                $statusCode = $response->getStatusCode();

                if ($statusCode === 429 || $statusCode === 503) {
                    if ($attempt === count($delays)) {
                        $this->debug('fetchArticle: rate limit persists after final retry — giving up');
                        $this->rateLimitExhausted = true;
                        return null;
                    }
                    $delay = $this->resolveRetryDelay($response, $delays[$attempt]);
                    $this->debug(sprintf('fetchArticle: %d — retrying in %ds...', $statusCode, $delay / 1_000_000));
                    usleep($delay);
                    continue;
                }

                $data = $response->toArray();
                break;
            } catch (\Throwable $e) {
                if ($attempt === count($delays)) {
                    $this->debug('fetchArticle failed: ' . $e->getMessage());
                    $this->rateLimitExhausted = true;
                    return null;
                }
                $delay = $delays[$attempt];
                $this->debug(sprintf('fetchArticle error, retrying in %ds...', $delay / 1_000_000));
                usleep($delay);
            }
        }

        $pages   = $data['query']['pages'] ?? [];
        $page    = reset($pages);

        if (!is_array($page)) {
            return null;
        }

        $extract = $page['extract'] ?? '';

        if (strlen(trim($extract)) < 80) {
            return null;
        }

        return [
            'title'      => $title,
            'extract'    => substr($extract, 0, 2000),
            'categories' => array_column($page['categories'] ?? [], 'title'),
        ];
    }

    private function resolveRetryDelay(ResponseInterface $response, int $fallback): int
    {
        $headers    = $response->getHeaders(false);
        $retryAfter = $headers['retry-after'][0] ?? null;

        if ($retryAfter !== null) {
            $seconds = is_numeric($retryAfter)
                ? (int) $retryAfter
                : max(0, strtotime($retryAfter) - time());

            if ($seconds > 0) {
                $this->debug(sprintf('Retry-After header: %ds', $seconds));
                return $seconds * 1_000_000;
            }
        }

        // Wikimedia policy: minimum 5s when no Retry-After header is present
        return max($fallback, 5_000_000);
    }

    /** @param string[] $categories */
    private function isExcludedByTopic(array $categories): bool
    {
        if (empty($this->excludedTopics)) {
            return false;
        }

        foreach ($categories as $category) {
            $normalized = strtolower(preg_replace('/^Kategorie:|^Category:/i', '', $category));
            foreach ($this->excludedTopics as $topic) {
                if (str_contains($normalized, strtolower(trim($topic)))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function generateQuestions(array $article, int $count, QuestionFormat $format, string $lang): array
    {
        $prompt = $this->buildPrompt($article, $count, $format, $lang);
        $raw    = $this->callLlm($prompt);

        if ($raw === null) {
            return [];
        }

        return $this->parseResponse($raw, $format);
    }

    private function buildPrompt(array $article, int $count, QuestionFormat $format, string $lang): string
    {
        $langLabel = $lang === 'de' ? 'German' : 'English';
        $title     = $article['title'];
        $extract   = $article['extract'];

        return match($format) {
            QuestionFormat::TrueFalse => <<<PROMPT
                Based on this Wikipedia article about "{$title}":

                {$extract}

                Generate {$count} true/false quiz statements in {$langLabel}. Mix true and false answers.
                Focus on widely known facts that any educated adult would recognise. Avoid specific dates, statistics, records, or details only specialists would know.

                STRICT RULES — violating any rule makes the entry unusable:
                1. Write STATEMENTS only, never questions. A statement never ends with "?".
                2. FORBIDDEN opening words (in any language): Welches, Welche, Welcher, Wer, Was, Wie, Wo, Wann, Warum, Which, Who, What, How, Where, When, Why, Is, Are, Does, Did, Can, Has.
                3. Each statement must be verifiable as TRUE or FALSE with no extra knowledge — no names, numbers, or choices needed as an answer.
                4. Name "{$title}" explicitly in every statement — never use "it", "he", "she", "they", "the film", "the book".
                5. Never translate proper nouns, names, brands, or places — keep them in their original language.

                CORRECT: {"statement": "{$title} was founded in 1923.", "isTrue": true}
                WRONG (question): {"statement": "When was {$title} founded?", "isTrue": false}
                WRONG (needs a name as answer): {"statement": "The capital of {$title} is called X.", "isTrue": false}

                Respond ONLY with a JSON array, no explanation or markdown:
                [{"statement": "...", "isTrue": true}, {"statement": "...", "isTrue": false}]
                PROMPT,

            QuestionFormat::MultipleChoice => <<<PROMPT
                Based on this Wikipedia article about "{$title}":

                {$extract}

                Generate {$count} multiple-choice quiz questions in {$langLabel}. Each must have exactly 4 options.
                Focus on widely known facts — what {$title} is, what it is known for, where it is, or what category it belongs to. Avoid specific dates, statistics, records, or details only specialists would know.
                The question MUST name the subject explicitly (e.g. "{$title}") — never use "it", "he", "she", "they", "the book", "the film" without naming it.
                Keep the question text short (maximum 8 words including the subject name).
                IMPORTANT: Never translate names, brands, places, person names, or proper nouns. Keep them in their original language.
                Respond ONLY with a JSON array, no explanation or markdown:
                [{"question": "...", "answer": "correct answer", "options": ["correct answer", "wrong1", "wrong2", "wrong3"]}]
                PROMPT,
        };
    }

    private function callLlm(string $prompt): ?string
    {
        if ($this->anthropicApiKey === '') {
            return null;
        }

        try {
            $response = $this->client->request('POST', $this->anthropicApiUrl, [
                'headers' => [
                    'x-api-key'         => $this->anthropicApiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => self::MODEL,
                    'max_tokens' => 1024,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ],
            ]);
            $data = $response->toArray();
        } catch (\Throwable) {
            return null;
        }

        $text = $data['content'][0]['text'] ?? null;
        if ($text !== null) {
            $this->debug("Anthropic response:\n{$text}");
        }

        return $text;
    }

    private function parseResponse(string $raw, QuestionFormat $format): array
    {
        if (!preg_match('/\[.*\]/s', $raw, $matches)) {
            return [];
        }

        try {
            $items = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn(array $item) => $this->buildQuestion($item, $format), $items)
        ));
    }

    private function buildQuestion(array $item, QuestionFormat $format): ?Question
    {
        return match($format) {
            QuestionFormat::TrueFalse      => $this->buildTrueFalse($item),
            QuestionFormat::MultipleChoice => $this->buildMultipleChoice($item),
        };
    }

    private function buildTrueFalse(array $item): ?Question
    {
        $statement = $item['statement'] ?? null;
        $isTrue    = $item['isTrue'] ?? null;

        if (!is_string($statement) || $statement === '' || !is_bool($isTrue)) {
            return null;
        }

        if (!$this->isValidStatement($statement)) {
            return null;
        }

        return new Question(
            id:       md5($statement),
            category: QuestionFormat::TrueFalse->value,
            type:     QuestionType::TrueFalse,
            question: $statement,
            answer:   $isTrue ? 'true' : 'false',
        );
    }

    private function buildMultipleChoice(array $item): ?Question
    {
        $question = $item['question'] ?? null;
        $answer   = $item['answer'] ?? null;
        $options  = $item['options'] ?? null;

        if (!is_string($question) || !is_string($answer) || !is_array($options) || count($options) < 2) {
            return null;
        }

        shuffle($options);

        return new Question(
            id:       md5($question),
            category: QuestionFormat::MultipleChoice->value,
            type:     QuestionType::MultipleChoice,
            question: $question,
            answer:   $answer,
            options:  array_values($options),
        );
    }

    private function isValidStatement(string $statement): bool
    {
        if (str_ends_with(rtrim($statement), '?')) {
            return false;
        }

        $firstWord = strtolower(strtok($statement, " \t\n"));
        $forbidden = [
            'welches', 'welche', 'welcher', 'welchem', 'welchen',
            'wer', 'was', 'wie', 'wo', 'wann', 'warum',
            'which', 'who', 'what', 'how', 'where', 'when', 'why',
            'is', 'are', 'does', 'did', 'can', 'has',
        ];

        return !in_array($firstWord, $forbidden, true);
    }
}