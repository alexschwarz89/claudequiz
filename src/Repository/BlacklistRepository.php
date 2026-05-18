<?php

declare(strict_types=1);

namespace App\Repository;

final class BlacklistRepository
{
    public function __construct(private readonly string $dataFile) {}

    /** @return string[] */
    public function loadIds(): array
    {
        if (!file_exists($this->dataFile)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->dataFile), true);

        return is_array($data) ? $data : [];
    }
}
