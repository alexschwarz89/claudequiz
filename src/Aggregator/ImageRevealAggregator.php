<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Model\QuestionType;
use App\Provider\ImageProviderInterface;

final class ImageRevealAggregator implements AggregatorInterface
{
    private const CATEGORY_ID = 'image_reveal';
    private const QUESTIONS = [
        'de' => 'Was ist auf dem Bild zu sehen?',
        'en' => 'What can you see in the image?',
    ];

    public function __construct(
        private readonly ImageProviderInterface $imageProvider,
        private readonly string $lang = 'de',
        private readonly ?\Closure $debugLogger = null,
        private readonly ?WordlistReader $wordlistReader = null,
    ) {}

    public function getCategoryId(): string
    {
        return self::CATEGORY_ID;
    }

    /** @return Question[] */
    public function fetch(int $count): array
    {
        $subjects = $this->resolveSubjects($count);
        $questions = [];
        $index     = 0;
        $interval  = $this->imageProvider->getMinRequestInterval();

        while (count($questions) < $count && $index < count($subjects)) {
            $question = $this->fetchSubject($subjects[$index]);
            if ($question !== null) {
                $questions[] = $question;
            }
            $index++;
            usleep($interval);
        }

        return $questions;
    }

    /** @return array<array{title: string, de: string, en: string}> */
    private function resolveSubjects(int $count): array
    {
        if ($this->wordlistReader === null) {
            return [];
        }

        return array_map(
            fn(string $word) => ['title' => $word, 'de' => $word, 'en' => $word],
            $this->wordlistReader->readRandom($count * 3),
        );
    }

    private function fetchSubject(array $subject): ?Question
    {
        $imageUrl = $this->imageProvider->fetchImage($subject['title']);
        if ($imageUrl === null) {
            $this->debug("No image found for '{$subject['title']}'");
            return null;
        }

        return new Question(
            id: md5($subject['title'] . $this->lang),
            category: self::CATEGORY_ID,
            type: QuestionType::ImageReveal,
            question: self::QUESTIONS[$this->lang] ?? self::QUESTIONS['de'],
            answer: $subject[$this->lang] ?? $subject['de'],
            imagePath: $imageUrl,
        );
    }

    private function debug(string $message): void
    {
        if ($this->debugLogger) {
            ($this->debugLogger)($message);
        }
    }


}
