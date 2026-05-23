<?php

declare(strict_types=1);

namespace App\Aggregator;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class YouTubeClient
{
    private const SEARCH_URL   = 'https://www.googleapis.com/youtube/v3/search';
    private const VIDEOS_URL   = 'https://www.googleapis.com/youtube/v3/videos';
    private const CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $apiKey,
        private readonly ?\Closure $debugLogger = null,
    ) {}

    private function debug(string $message): void
    {
        if ($this->debugLogger !== null) {
            ($this->debugLogger)($message);
        }
    }

    /**
     * Makes a GET request and returns the decoded JSON body.
     * On HTTP 429 it sleeps for the Retry-After duration (capped at 120 s) and retries once.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     * @throws \Throwable
     */
    private function requestJson(string $url, array $query): array
    {
        $response = $this->client->request('GET', $url, ['query' => $query]);

        if ($response->getStatusCode() === 429) {
            $retryAfter = min((int) ($response->getHeaders(false)['retry-after'][0] ?? 60), 120);
            $this->debug(sprintf('Rate limited — retrying after %ds', $retryAfter));
            sleep($retryAfter);
            $response = $this->client->request('GET', $url, ['query' => $query]);
        }

        return $response->toArray();
    }

    /**
     * @return array<array{videoId: string, title: string, channelId: string, channelTitle: string}>
     */
    public function searchVideos(string $query, int $maxResults = 50, array $extraParams = []): array
    {
        try {
            $data = $this->requestJson(self::SEARCH_URL, array_merge([
                'key'        => $this->apiKey,
                'q'          => $query,
                'part'       => 'snippet',
                'type'       => 'video',
                'maxResults' => $maxResults,
            ], $extraParams));
        } catch (\Throwable $e) {
            $this->debug('searchVideos failed: ' . $e->getMessage());
            return [];
        }

        $results = [];
        foreach ($data['items'] ?? [] as $item) {
            $videoId = $item['id']['videoId'] ?? null;
            if ($videoId === null) {
                continue;
            }
            $results[] = [
                'videoId'      => $videoId,
                'title'        => $item['snippet']['title'] ?? '',
                'channelId'    => $item['snippet']['channelId'] ?? '',
                'channelTitle' => $item['snippet']['channelTitle'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * @param string[] $videoIds
     * @return array<string, int> videoId => viewCount
     */
    public function fetchVideoViewCounts(array $videoIds): array
    {
        if (empty($videoIds)) {
            return [];
        }

        try {
            $data = $this->requestJson(self::VIDEOS_URL, [
                'key'  => $this->apiKey,
                'id'   => implode(',', array_slice($videoIds, 0, 50)),
                'part' => 'statistics',
            ]);
        } catch (\Throwable $e) {
            $this->debug('fetchVideoViewCounts failed: ' . $e->getMessage());
            return [];
        }

        $counts = [];
        foreach ($data['items'] ?? [] as $item) {
            $counts[$item['id']] = (int) ($item['statistics']['viewCount'] ?? 0);
        }

        return $counts;
    }

    /**
     * Resolves a @handle to {channelId, channelTitle}.
     * Costs 1 quota unit.
     *
     * @return array{channelId: string, channelTitle: string}|null
     */
    public function resolveHandle(string $handle): ?array
    {
        try {
            $data = $this->requestJson(self::CHANNELS_URL, [
                'key'       => $this->apiKey,
                'forHandle' => $handle,
                'part'      => 'snippet',
            ]);
        } catch (\Throwable $e) {
            $this->debug('resolveHandle failed for ' . $handle . ': ' . $e->getMessage());
            return null;
        }

        $item = $data['items'][0] ?? null;
        if ($item === null) {
            $this->debug('Handle not found: ' . $handle);
            return null;
        }

        return [
            'channelId'    => $item['id'],
            'channelTitle' => $item['snippet']['title'] ?? '',
        ];
    }

    /**
     * Returns the top videos of a channel ordered by view count.
     * Costs 100 quota units.
     *
     * @return array<array{videoId: string, title: string, channelId: string, channelTitle: string}>
     */
    public function fetchChannelTopVideos(string $channelId, int $maxResults = 10): array
    {
        try {
            $data = $this->requestJson(self::SEARCH_URL, [
                'key'        => $this->apiKey,
                'channelId'  => $channelId,
                'part'       => 'snippet',
                'type'       => 'video',
                'order'      => 'viewCount',
                'maxResults' => $maxResults,
            ]);
        } catch (\Throwable $e) {
            $this->debug('fetchChannelTopVideos failed for ' . $channelId . ': ' . $e->getMessage());
            return [];
        }

        $results = [];
        foreach ($data['items'] ?? [] as $item) {
            $videoId = $item['id']['videoId'] ?? null;
            if ($videoId === null) {
                continue;
            }
            $results[] = [
                'videoId'      => $videoId,
                'title'        => $item['snippet']['title'] ?? '',
                'channelId'    => $item['snippet']['channelId'] ?? '',
                'channelTitle' => $item['snippet']['channelTitle'] ?? '',
            ];
        }

        return $results;
    }
}
