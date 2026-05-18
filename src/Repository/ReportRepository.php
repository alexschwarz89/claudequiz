<?php

declare(strict_types=1);

namespace App\Repository;

final class ReportRepository
{
    public function __construct(private readonly string $filePath) {}

    public function add(string $id): void
    {
        $ids   = $this->load();
        $ids[] = $id;

        file_put_contents(
            $this->filePath,
            json_encode(array_values(array_unique($ids)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /** @return string[] */
    public function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        return json_decode(file_get_contents($this->filePath), true) ?? [];
    }
}
