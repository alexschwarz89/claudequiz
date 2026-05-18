<?php

declare(strict_types=1);

namespace App\Provider;

use App\Model\Question;
use App\Model\QuestionType;

final class FileProvider implements QuestionProviderInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {}

    /** @return Question[] */
    public function provide(int $count, QuestionFormat $format, string $lang): array
    {
        $items = $this->loadItems($format);
        shuffle($items);
        $selected = array_slice($items, 0, min($count, count($items)));

        return array_values(array_filter(
            array_map(fn(array $item) => $this->buildQuestion($item, $format), $selected)
        ));
    }

    private function loadItems(QuestionFormat $format): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        try {
            $data = json_decode(file_get_contents($this->filePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return $data[$format->value] ?? [];
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
        $isTrue    = $item['is_true'] ?? null;

        if ($statement === null || $isTrue === null) {
            return null;
        }

        return new Question(
            id:       md5($statement),
            category: QuestionFormat::TrueFalse->value,
            type:     QuestionType::TrueFalse,
            question: $statement,
            answer:   (bool) $isTrue ? 'true' : 'false',
        );
    }

    private function buildMultipleChoice(array $item): ?Question
    {
        $question = $item['question'] ?? null;
        $answer   = $item['answer'] ?? null;
        $options  = $item['options'] ?? null;

        if ($question === null || $answer === null || !is_array($options) || count($options) < 2) {
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
}