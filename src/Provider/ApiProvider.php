<?php

declare(strict_types=1);

namespace App\Provider;

use App\Model\Question;
use App\Model\QuestionType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiProvider implements QuestionProviderInterface
{
    private const RATE_LIMIT_SECONDS = 5;
    private const MIN_PER_CATEGORY   = 10;

    private float $lastCallTime = 0.0;

    /**
     * @param int[] $categories
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $apiUrl,
        private readonly array $categories,
        private readonly ?\Closure $debugLogger = null,
    ) {}

    /** @return Question[] */
    public function provide(int $count, QuestionFormat $format, string $lang): array
    {
        $type        = $format === QuestionFormat::TrueFalse ? 'boolean' : 'multiple';
        $perCategory = max($count, self::MIN_PER_CATEGORY);
        $questions   = [];

        foreach ($this->categories as $categoryId) {
            $questions = array_merge($questions, $this->fetchCategory((int) $categoryId, $perCategory, $type, $format));
        }

        return $questions;
    }

    /** @return Question[] */
    private function fetchCategory(int $categoryId, int $count, string $type, QuestionFormat $format): array
    {
        $this->waitForRateLimit();

        $query = ['amount' => min($count, 100), 'type' => $type, 'category' => $categoryId];

        if ($this->debugLogger !== null) {
            ($this->debugLogger)(sprintf('GET %s?%s', $this->apiUrl, http_build_query($query)));
        }

        try {
            $response = $this->client->request('GET', $this->apiUrl, ['query' => $query]);
            $data     = $response->toArray();
        } catch (\Throwable) {
            return [];
        }

        if (($data['response_code'] ?? -1) !== 0) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn(array $item) => $this->parseItem($item, $format), $data['results'] ?? [])
        ));
    }

    private function parseItem(array $item, QuestionFormat $format): ?Question
    {
        $questionText = html_entity_decode($item['question'] ?? '', ENT_QUOTES | ENT_HTML5);
        $correct      = html_entity_decode($item['correct_answer'] ?? '', ENT_QUOTES | ENT_HTML5);

        if ($questionText === '' || $correct === '') {
            return null;
        }

        return match($format) {
            QuestionFormat::TrueFalse      => $this->buildTrueFalse($questionText, $correct),
            QuestionFormat::MultipleChoice => $this->buildMultipleChoice($questionText, $correct, $item['incorrect_answers'] ?? []),
        };
    }

    private function waitForRateLimit(): void
    {
        $wait = (int) ((self::RATE_LIMIT_SECONDS - (microtime(true) - $this->lastCallTime)) * 1_000_000);

        if ($wait > 0) {
            usleep($wait);
        }

        $this->lastCallTime = microtime(true);
    }

    private function buildTrueFalse(string $question, string $correct): ?Question
    {
        if (!in_array(strtolower($correct), ['true', 'false'], true)) {
            return null;
        }

        return new Question(
            id:       md5($question),
            category: QuestionFormat::TrueFalse->value,
            type:     QuestionType::TrueFalse,
            question: $question,
            answer:   strtolower($correct) === 'true' ? 'true' : 'false',
        );
    }

    private function buildMultipleChoice(string $question, string $correct, array $incorrect): Question
    {
        $options = array_map(
            fn(string $option) => html_entity_decode($option, ENT_QUOTES | ENT_HTML5),
            $incorrect
        );
        $options[] = $correct;
        shuffle($options);

        return new Question(
            id:       md5($question),
            category: QuestionFormat::MultipleChoice->value,
            type:     QuestionType::MultipleChoice,
            question: $question,
            answer:   $correct,
            options:  array_values($options),
        );
    }
}