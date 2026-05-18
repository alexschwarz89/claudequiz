<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PixabayImageProvider implements ImageProviderInterface
{
    private const MIN_REQUEST_INTERVAL = 600_000; // 600ms for 100 requests per 60 seconds

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $apiKey,
        private readonly string $apiUrl,
    ) {}

    public function fetchImage(string $searchTerm): ?string
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = $this->client->request('GET', $this->apiUrl, [
                'query' => [
                    'key'        => $this->apiKey,
                    'q'          => $searchTerm,
                    'image_type' => 'photo',
                    'per_page'   => 3,
                    'safesearch' => 'true',
                    'order'      => 'popular',
                ],
            ]);

            $data = $response->toArray();
            $hits = $data['hits'] ?? [];

            if (empty($hits)) {
                return null;
            }

            $hit = reset($hits);
            return $hit['largeImageURL'] ?? $hit['webformatURL'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getMinRequestInterval(): int
    {
        return self::MIN_REQUEST_INTERVAL;
    }
}