<?php

declare(strict_types=1);

namespace App\Provider;

interface ImageProviderInterface
{
    /**
     * Fetch image URL for a given search term or title
     * Returns null if not found
     */
    public function fetchImage(string $term): ?string;

    /**
     * Get minimum delay between requests in microseconds
     * Used to respect API rate limits
     */
    public function getMinRequestInterval(): int;
}
