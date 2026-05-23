<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Model\QuestionType;

final class YouTubeCreatorAggregator implements AggregatorInterface
{
    private const CATEGORY_ID   = 'youtube_creator';
    private const CLIP_START    = 45;
    private const CLIP_DURATION = 20;

    private const QUESTIONS = [
        'de' => 'Von welchem YouTube-Kanal stammt dieses Video?',
        'en' => 'Which YouTube channel created this video?',
    ];

    public function __construct(
        private readonly YouTubeClient $youtube,
        private readonly VideoDownloader $downloader,
        private readonly string $creatorsFile,
        private readonly string $lang = 'de',
        private readonly ?\Closure $debugLogger = null,
    ) {}

    private function debug(string $message): void
    {
        if ($this->debugLogger !== null) {
            ($this->debugLogger)($message);
        }
    }

    public function getCategoryId(): string
    {
        return self::CATEGORY_ID;
    }

    /** @return Question[] */
    public function fetch(int $count): array
    {
        $entries = $this->loadChannelList();

        if (empty($entries)) {
            $this->debug('Channel list is empty or file not found: ' . $this->creatorsFile);
            return [];
        }

        shuffle($entries);

        $questions = [];

        foreach ($entries as $entry) {
            if (count($questions) >= $count) {
                break;
            }

            $channel = $this->resolveChannel($entry);
            if ($channel === null) {
                continue;
            }

            $video = $this->pickVideo($channel['channelId']);
            if ($video === null) {
                $this->debug(sprintf('No videos for: %s', $channel['channelTitle']));
                continue;
            }

            $question = $this->buildQuestion($video, $channel['channelTitle']);
            if ($question !== null) {
                $questions[] = $question;
                $this->debug(sprintf('Added: %s', $channel['channelTitle']));
            }

            usleep(500_000);
        }

        return $questions;
    }

    /**
     * File format (one entry per line):
     *   @handle              — display name taken from the YouTube API
     *   @handle|Display Name — override the display name used as the quiz answer
     *
     * @return array<array{handle: string, displayName: ?string}>
     */
    private function loadChannelList(): array
    {
        if (!file_exists($this->creatorsFile) || !is_readable($this->creatorsFile)) {
            return [];
        }

        $lines   = file($this->creatorsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_contains($line, '|')) {
                [$handle, $displayName] = explode('|', $line, 2);
                $entries[] = ['handle' => trim($handle), 'displayName' => trim($displayName) ?: null];
            } else {
                $entries[] = ['handle' => $line, 'displayName' => null];
            }
        }

        return $entries;
    }

    /**
     * @param array{handle: string, displayName: ?string} $entry
     * @return array{channelId: string, channelTitle: string}|null
     */
    private function resolveChannel(array $entry): ?array
    {
        $resolved = $this->youtube->resolveHandle($entry['handle']);

        if ($resolved === null) {
            $this->debug('Could not resolve: ' . $entry['handle']);
            return null;
        }

        return [
            'channelId'    => $resolved['channelId'],
            'channelTitle' => $entry['displayName'] ?? $resolved['channelTitle'],
        ];
    }

    /** @return array{videoId: string, title: string, channelId: string, channelTitle: string}|null */
    private function pickVideo(string $channelId): ?array
    {
        $videos = $this->youtube->fetchChannelTopVideos($channelId, 10);

        if (empty($videos)) {
            return null;
        }

        // Pick randomly from the top 5 to avoid always using the #1 most-viewed video
        $pool = array_slice($videos, 0, min(5, count($videos)));

        return $pool[array_rand($pool)];
    }

    private function buildQuestion(array $video, string $channelTitle): ?Question
    {
        $videoId  = $video['videoId'];
        $filename = sprintf('creator-%s.mp4', md5($videoId));
        $webPath  = $this->downloader->download($videoId, $filename, self::CLIP_START, self::CLIP_DURATION);

        if ($webPath === null) {
            $this->debug(sprintf('Download failed: %s', $videoId));
            return null;
        }

        return new Question(
            id:        md5($videoId . $this->lang),
            category:  self::CATEGORY_ID,
            type:      QuestionType::YouTubeCreator,
            question:  self::QUESTIONS[$this->lang] ?? self::QUESTIONS['de'],
            answer:    $channelTitle,
            videoPath: $webPath,
        );
    }
}
