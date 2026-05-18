<?php

declare(strict_types=1);

namespace App\Provider;

use App\Model\Question;
use App\Translator\DeepLTranslator;

final class TranslatingProvider implements QuestionProviderInterface
{
    public function __construct(
        private readonly QuestionProviderInterface $inner,
        private readonly DeepLTranslator $translator,
        private readonly string $targetLang,
    ) {}

    /** @return Question[] */
    public function provide(int $count, QuestionFormat $format, string $lang): array
    {
        $questions = $this->inner->provide($count, $format, $lang);

        return $this->translateQuestions($questions);
    }

    /** @return Question[] */
    private function translateQuestions(array $questions): array
    {
        [$texts, $map] = $this->buildTextMap($questions);

        if (empty($texts)) {
            return $questions;
        }

        $translated = $this->translator->translate($texts, $this->targetLang);

        return array_map(
            fn(Question $question, array $translationEntry) => $this->applyTranslation($question, $translationEntry, $translated),
            $questions,
            $map
        );
    }

    private function buildTextMap(array $questions): array
    {
        $texts = [];
        $map   = [];

        foreach ($questions as $question) {
            $entry   = ['questionIdx' => count($texts)];
            $texts[] = $question->question;

            if ($question->options !== null) {
                $entry = array_merge($entry, [
                    'answerPos'   => array_search($question->answer, $question->options, true),
                    'optionStart' => count($texts),
                    'optionCount' => count($question->options),
                ]);
                array_push($texts, ...$question->options);
            }

            $map[] = $entry;
        }

        return [$texts, $map];
    }

    private function applyTranslation(Question $question, array $translationEntry, array $translated): Question
    {
        $translatedQuestion = $translated[$translationEntry['questionIdx']] ?? $question->question;

        if ($question->options === null) {
            return $this->rebuildQuestion($question, $translatedQuestion, $question->answer, null);
        }

        $translatedOptions = array_slice($translated, $translationEntry['optionStart'], $translationEntry['optionCount']);

        if (count($translatedOptions) !== $translationEntry['optionCount']) {
            return $question;
        }

        $answerPos        = $translationEntry['answerPos'];
        $translatedAnswer = is_int($answerPos) ? ($translatedOptions[$answerPos] ?? $question->answer) : $question->answer;

        return $this->rebuildQuestion($question, $translatedQuestion, $translatedAnswer, $translatedOptions);
    }

    private function rebuildQuestion(Question $question, string $translatedQuestion, string $answer, ?array $options): Question
    {
        return new Question(
            id:        $question->id,
            category:  $question->category,
            type:      $question->type,
            question:  $translatedQuestion,
            answer:    $answer,
            options:   $options,
            imagePath: $question->imagePath,
            audioUrl:  $question->audioUrl,
            latitude:  $question->latitude,
            longitude: $question->longitude,
        );
    }
}
