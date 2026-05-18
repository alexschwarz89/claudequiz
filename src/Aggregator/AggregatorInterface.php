<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;

interface AggregatorInterface
{
    public function getCategoryId(): string;

    /** @return Question[] */
    public function fetch(int $count): array;
}
