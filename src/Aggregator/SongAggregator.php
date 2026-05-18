<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Model\QuestionType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SongAggregator implements AggregatorInterface
{
    private const CATEGORY_ID = 'song_guess';
    private const BATCH_SIZE  = 50;
    private const CHART_TOTAL = 100;
    private const QUESTIONS   = [
        'de' => 'Interpret und/oder Songtitel erraten',
        'en' => 'Guess the artist and/or song title',
    ];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $deezerChartUrl,
        private readonly string $audioDir,
        private readonly string $audioWebPath,
        private readonly string $lang = 'de',
    ) {}

    public function getCategoryId(): string
    {
        return self::CATEGORY_ID;
    }

    /** @return Question[] */
    public function fetch(int $count): array
    {
        $tracks = $this->fetchTracks($count);

        $questions = array_filter(
            array_map(fn(array $track) => $this->parseTrack($track), $tracks),
        );

        return array_slice(array_values($questions), 0, $count);
    }

    private function fetchTracks(int $count): array
    {
        $tracks     = [];
        $startIndex = random_int(0, self::CHART_TOTAL - 1);
        $offset     = $startIndex;
        $fetched    = 0;

        while ($fetched < self::CHART_TOTAL && count($tracks) < $count) {
            $limit    = min(self::BATCH_SIZE, $count - count($tracks));
            $batch    = $this->fetchBatch($offset % self::CHART_TOTAL, $limit);
            $tracks   = array_merge($tracks, $batch);
            $fetched += $limit;
            $offset  += $limit;
        }

        return $tracks;
    }

    private function fetchBatch(int $index, int $limit): array
    {
        try {
            $response = $this->client->request('GET', $this->deezerChartUrl, [
                'query' => ['index' => $index, 'limit' => $limit],
            ]);

            return $response->toArray()['data'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseTrack(array $track): ?Question
    {
        $previewUrl = $track['preview'] ?? null;
        if (empty($previewUrl)) {
            return null;
        }

        $filename  = basename(parse_url($previewUrl, PHP_URL_PATH) ?? $previewUrl);
        $localPath = $this->downloadPreview($previewUrl, $filename);
        if ($localPath === null) {
            return null;
        }

        $artist = $track['artist']['name'] ?? 'Unknown';
        $title  = $track['title'] ?? 'Unknown';

        return new Question(
            id: md5($filename),
            category: self::CATEGORY_ID,
            type: QuestionType::SongGuess,
            question: self::QUESTIONS[$this->lang] ?? self::QUESTIONS['de'],
            answer: sprintf('%s — %s', $artist, $title),
            audioUrl: $localPath,
        );
    }

    private function downloadPreview(string $url, string $filename): ?string
    {
        $destination = $this->audioDir . '/' . $filename;

        if (file_exists($destination)) {
            return $this->audioWebPath . '/' . $filename;
        }

        try {
            $response = $this->client->request('GET', $url);
            file_put_contents($destination, $response->getContent());

            return $this->audioWebPath . '/' . $filename;
        } catch (\Throwable) {
            return null;
        }
    }
}