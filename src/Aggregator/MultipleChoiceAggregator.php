<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Provider\QuestionFormat;
use App\Provider\QuestionProviderInterface;

final class MultipleChoiceAggregator implements AggregatorInterface
{
    private const CATEGORY_ID = 'multiple_choice';

    public function __construct(
        private readonly QuestionProviderInterface $provider,
        private readonly string $lang = 'de',
    ) {}

    public function getCategoryId(): string
    {
        return self::CATEGORY_ID;
    }

    /** @return Question[] */
    public function fetch(int $count): array
    {
        return $this->provider->provide($count, QuestionFormat::MultipleChoice, $this->lang);
    }
}
