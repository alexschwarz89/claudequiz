<?php

declare(strict_types=1);

namespace App\Translator;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DeepLTranslator
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $apiKey,
        private readonly string $apiUrlFree,
        private readonly string $apiUrlPro,
    ) {}

    /**
     * @param  string[] $texts
     * @return string[] Same-length array of translated strings; returns $texts unchanged on failure.
     */
    public function translate(array $texts, string $targetLang, string $sourceLang = 'EN'): array
    {
        try {
            $response = $this->client->request('POST', $this->resolveApiUrl(), [
                'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $this->apiKey],
                'json'    => [
                    'text'        => $texts,
                    'target_lang' => strtoupper($targetLang),
                    'source_lang' => strtoupper($sourceLang),
                ],
            ]);
            $data = $response->toArray();
        } catch (\Throwable) {
            return $texts;
        }

        $result = array_column($data['translations'] ?? [], 'text');

        return count($result) === count($texts) ? $result : $texts;
    }

    private function resolveApiUrl(): string
    {
        return str_ends_with($this->apiKey, ':fx') ? $this->apiUrlFree : $this->apiUrlPro;
    }
}