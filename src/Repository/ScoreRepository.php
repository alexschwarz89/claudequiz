<?php

declare(strict_types=1);

namespace App\Repository;

final class ScoreRepository
{
    private const FILE_PATH   = __DIR__ . '/../../data/scores.json';
    private const MAX_ENTRIES = 10;

    /** @return array<array{name: string, score: int, total: int, date: string}> */
    public function load(): array
    {
        if (!file_exists(self::FILE_PATH)) {
            return [];
        }

        $data = json_decode(file_get_contents(self::FILE_PATH), true) ?? [];

        return $data['scores'] ?? [];
    }

    public function qualifies(int $score): bool
    {
        if ($score <= 0) {
            return false;
        }

        $entries = $this->load();

        if (count($entries) < self::MAX_ENTRIES) {
            return true;
        }

        return $score >= ($entries[count($entries) - 1]['score'] ?? 0);
    }

    public function save(string $name, int $score, int $total): void
    {
        $entries   = $this->load();
        $entries[] = ['name' => $name, 'score' => $score, 'total' => $total, 'date' => date('Y-m-d')];

        usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
        $entries = array_slice($entries, 0, self::MAX_ENTRIES);

        file_put_contents(
            self::FILE_PATH,
            json_encode(['scores' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }
}
