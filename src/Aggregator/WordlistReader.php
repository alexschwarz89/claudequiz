<?php

declare(strict_types=1);

namespace App\Aggregator;

final class WordlistReader
{
    public function __construct(
        private string $filePath,
        private WordlistFormat $format = WordlistFormat::Newline,
    ) {}

    /** @return string[] */
    public function readSequential(int $count): array
    {
        if ($this->format === WordlistFormat::Csv) {
            return array_slice($this->parseAllWords(), 0, $count);
        }

        return $this->readSequentialLines($count);
    }

    /** @return string[] */
    public function readRandom(int $count): array
    {
        if ($this->format === WordlistFormat::Csv) {
            $all = $this->parseAllWords();
            shuffle($all);
            return array_slice($all, 0, $count);
        }

        return $this->readRandomLines($count);
    }

    /** @return string[] */
    private function parseAllWords(): array
    {
        $content = @file_get_contents($this->filePath);
        if ($content === false) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $content))
        ));
    }

    /** @return string[] */
    private function readSequentialLines(int $count): array
    {
        $handle = @fopen($this->filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $words = [];
        while (count($words) < $count && !feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $word = trim($line);
            if ($word !== '') {
                $words[] = $word;
            }
        }

        fclose($handle);

        return $words;
    }

    /** @return string[] */
    private function readRandomLines(int $count): array
    {
        $fileSize = @filesize($this->filePath);
        if ($fileSize === false || $fileSize === 0) {
            return [];
        }

        $handle = @fopen($this->filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $words       = [];
        $seen        = [];
        $maxAttempts = $count * 6;
        $attempts    = 0;

        while (count($words) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $pos = random_int(0, max(0, $fileSize - 2));
            fseek($handle, $pos);
            fgets($handle);
            $line = fgets($handle);

            if ($line === false) {
                fseek($handle, 0);
                $line = fgets($handle);
            }

            $word = trim((string) $line);
            if ($word !== '' && !isset($seen[$word])) {
                $seen[$word] = true;
                $words[]     = $word;
            }
        }

        fclose($handle);

        return $words;
    }
}
