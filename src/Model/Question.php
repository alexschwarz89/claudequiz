<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Question
{
    public function __construct(
        public string $id,
        public string $category,
        public QuestionType $type,
        public string $question,
        public string $answer,
        public ?array $options = null,
        public ?string $imagePath = null,
        public ?string $audioUrl = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $videoPath = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            category: $data['category'],
            type: QuestionType::from($data['type']),
            question: $data['question'],
            answer: $data['answer'],
            options: $data['options'] ?? null,
            imagePath: $data['image_path'] ?? null,
            audioUrl: $data['audio_url'] ?? null,
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            videoPath: $data['video_path'] ?? null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'category' => $this->category,
            'type' => $this->type->value,
            'question' => $this->question,
            'answer' => $this->answer,
        ];

        if ($this->options !== null) {
            $data['options'] = $this->options;
        }
        if ($this->imagePath !== null) {
            $data['image_path'] = $this->imagePath;
        }
        if ($this->audioUrl !== null) {
            $data['audio_url'] = $this->audioUrl;
        }
        if ($this->latitude !== null) {
            $data['latitude'] = $this->latitude;
            $data['longitude'] = $this->longitude;
        }
        if ($this->videoPath !== null) {
            $data['video_path'] = $this->videoPath;
        }

        return $data;
    }
}
