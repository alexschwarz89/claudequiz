<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Question;

final class QuestionRepository
{
    public function __construct(private string $dataFile) {}

    public function exists(): bool
    {
        return file_exists($this->dataFile);
    }

    public function findAllRaw(): array
    {
        if (!$this->exists()) {
            return ['questions' => [], 'total' => 0];
        }

        $content = file_get_contents($this->dataFile);
        $data = json_decode($content, true);

        shuffle($data['questions']);

        return [
            'questions' => $data['questions'] ?? [],
            'total' => $data['total'] ?? 0,
        ];
    }

    /** @return Question[] */
    public function load(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $content = file_get_contents($this->dataFile);
        $data = json_decode($content, true);

        return array_map(
            fn(array $item) => Question::fromArray($item),
            $data['questions'] ?? []
        );
    }

    /** @param Question[] $questions */
    public function save(array $questions): void
    {
        $dir = dirname($this->dataFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'version' => '1.0',
            'total' => count($questions),
            'questions' => array_map(
                fn(Question $question) => $question->toArray(),
                $questions
            ),
        ];

        file_put_contents(
            $this->dataFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
