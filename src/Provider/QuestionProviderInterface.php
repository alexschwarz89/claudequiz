<?php

declare(strict_types=1);

namespace App\Provider;

use App\Model\Question;

interface QuestionProviderInterface
{
    /** @return Question[] */
    public function provide(int $count, QuestionFormat $format, string $lang): array;
}
