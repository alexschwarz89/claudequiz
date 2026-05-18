<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WikimediaImageProvider implements ImageProviderInterface
{
    private const MIN_REQUEST_INTERVAL = 1_500_000; // 1.5s to respect Wikipedia rate limits

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $apiUrl,
    ) {}

    public function fetchImage(string $title): ?string
    {
        $maxRetries = 3;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = $this->client->request('GET', $this->apiUrl, [
                    'query' => [
                        'action'      => 'query',
                        'titles'      => $title,
                        'prop'        => 'pageimages',
                        'format'      => 'json',
                        'pithumbsize' => 600,
                    ],
                ]);
                $data = $response->toArray();
                break;
            } catch (\Throwable) {
                if ($attempt === $maxRetries - 1) {
                    return null;
                }
                usleep((2 ** $attempt) * 2_000_000);
            }
        }

        $pages = $data['query']['pages'] ?? [];
        if (empty($pages)) {
            return null;
        }

        $page = reset($pages);
        if (!is_array($page)) {
            return null;
        }

        return $page['thumbnail']['source'] ?? null;
    }

    public function getMinRequestInterval(): int
    {
        return self::MIN_REQUEST_INTERVAL;
    }
}