<?php

declare(strict_types=1);

namespace App\Aggregator;

final class VideoDownloader
{
    public function __construct(
        private readonly string $videoDir,
        private readonly string $videoWebPath,
        private readonly string $ytDlpBin = 'yt-dlp',
        private readonly ?\Closure $debugLogger = null,
    ) {}

    private function debug(string $message): void
    {
        if ($this->debugLogger !== null) {
            ($this->debugLogger)($message);
        }
    }

    public function exists(string $filename): bool
    {
        return file_exists($this->videoDir . '/' . $filename);
    }

    /**
     * Downloads a 20-second clip using yt-dlp --download-sections.
     * Returns the public web path on success, null on failure.
     */
    public function download(string $videoId, string $filename, int $startSeconds, int $durationSeconds): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,20}$/', $videoId)) {
            $this->debug("Invalid video ID: {$videoId}");
            return null;
        }

        $outputPath = $this->videoDir . '/' . $filename;

        if (file_exists($outputPath)) {
            return $this->videoWebPath . '/' . $filename;
        }

        $this->ensureDir();

        $end  = $startSeconds + $durationSeconds;
        $url  = 'https://www.youtube.com/watch?v=' . $videoId;
        $args = [
            escapeshellarg($this->ytDlpBin),
            '--download-sections', escapeshellarg(sprintf('*%d-%d', $startSeconds, $end)),
            '--no-playlist',
            '-f', escapeshellarg('best[height<=480][ext=mp4]/bestvideo[height<=480][vcodec^=avc]+bestaudio[acodec^=mp4a]/best[height<=480]'),
            '--merge-output-format', 'mp4',
            '--concurrent-fragments', '5',
            '--no-warnings',
            '--quiet',
            '-o', escapeshellarg($outputPath),
            escapeshellarg($url),
        ];

        $this->debug(sprintf('Downloading %s [%ds–%ds]', $videoId, $startSeconds, $end));
        exec(implode(' ', $args) . ' 2>&1', $lines, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            $this->debug('yt-dlp failed (exit ' . $exitCode . '): ' . implode(' ', $lines));
            return null;
        }

        return $this->videoWebPath . '/' . $filename;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->videoDir)) {
            mkdir($this->videoDir, 0755, true);
        }
    }
}
