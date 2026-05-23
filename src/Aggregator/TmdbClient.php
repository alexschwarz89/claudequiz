<?php

declare(strict_types=1);

namespace App\Aggregator;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TmdbClient
{
    private const BASE_URL       = 'https://api.themoviedb.org/3';
    private const MIN_VOTE_COUNT = 200;

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

    /** @return array<array{title: string}> */
    public function fetchPopularMovies(int $page = 1, string $lang = 'en'): array
    {
        return $this->fetchMovies('/movie/popular', $page, $lang);
    }

    /** @return array<array{title: string}> */
    public function fetchTopRatedMovies(int $page = 1, string $lang = 'en'): array
    {
        return $this->fetchMovies('/movie/top_rated', $page, $lang);
    }

    /** @return array<array{title: string}> */
    private function fetchMovies(string $path, int $page, string $lang = 'en'): array
    {
        $tmdbLang = match ($lang) {
            'de'    => 'de-DE',
            default => 'en-US',
        };

        try {
            $response = $this->client->request('GET', self::BASE_URL . $path, [
                'query' => ['api_key' => $this->apiKey, 'page' => $page, 'language' => $tmdbLang],
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->debug('TMDB request failed: ' . $e->getMessage());
            return [];
        }

        $movies = [];
        foreach ($data['results'] ?? [] as $item) {
            if ((int) ($item['vote_count'] ?? 0) < self::MIN_VOTE_COUNT) {
                continue;
            }
            $title = trim($item['title'] ?? '');
            if ($title !== '') {
                $movies[] = ['title' => $title];
            }
        }

        return $movies;
    }
}
