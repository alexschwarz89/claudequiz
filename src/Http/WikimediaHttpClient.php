<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class WikimediaHttpClient implements HttpClientInterface
{
    private const DEFAULT_HEADERS = [
        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language' => 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control'   => 'no-cache',
        'Pragma'          => 'no-cache',
    ];

    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly string $userAgent,
    ) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['headers'] = array_merge(
            self::DEFAULT_HEADERS,
            ['User-Agent' => $this->userAgent],
            $options['headers'] ?? [],
        );

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->userAgent);
    }
}
