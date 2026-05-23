<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Model\QuestionType;

final class FilmSceneAggregator implements AggregatorInterface
{
    private const CATEGORY_ID   = 'film_scene';
    private const CLIP_START    = 30;
    private const CLIP_DURATION = 20;

    private const QUESTIONS = [
        'de' => 'Welchem Film gehört dieser Trailer?',
        'en' => 'Which movie does this trailer belong to?',
    ];

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly YouTubeClient $youtube,
        private readonly VideoDownloader $downloader,
        private readonly string $lang = 'de',
        private readonly int $minViews = 100_000,
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
        $movies = $this->fetchMovieCandidates($count * 4);

        if (empty($movies)) {
            return [];
        }

        shuffle($movies);

        $questions = [];
        foreach ($movies as $movie) {
            if (count($questions) >= $count) {
                break;
            }

            $videoId = $this->findTrailerVideoId($movie['title']);
            if ($videoId === null) {
                $this->debug(sprintf('No trailer found: %s', $movie['title']));
                continue;
            }

            $question = $this->buildQuestion($movie['title'], $videoId);
            if ($question !== null) {
                $questions[] = $question;
                $this->debug(sprintf('Added: %s', $movie['title']));
            }

            usleep(500_000);
        }

        return $questions;
    }

    /** @return array<array{title: string}> */
    private function fetchMovieCandidates(int $limit): array
    {
        $popularPage  = random_int(1, 8);
        $topRatedPage = random_int(1, 8);

        $movies = array_merge(
            $this->tmdb->fetchPopularMovies($popularPage, $this->lang),
            $this->tmdb->fetchTopRatedMovies($topRatedPage, $this->lang),
        );

        shuffle($movies);

        return array_slice($movies, 0, $limit);
    }

    private function findTrailerVideoId(string $movieTitle): ?string
    {
        $queries = $this->lang === 'de'
            ? [$movieTitle . ' Trailer Deutsch', $movieTitle . ' trailer']
            : [$movieTitle . ' official trailer', $movieTitle . ' trailer'];

        foreach ($queries as $index => $query) {
            $threshold = $index === 0 ? $this->minViews : 1;
            $videoId   = $this->searchBestTrailer($query, $threshold);
            if ($videoId !== null) {
                return $videoId;
            }
        }

        return null;
    }

    private function searchBestTrailer(string $query, int $minViews): ?string
    {
        $results = $this->youtube->searchVideos($query, 5, ['videoDuration' => 'short']);

        if (empty($results)) {
            return null;
        }

        $videoIds   = array_column($results, 'videoId');
        $viewCounts = $this->youtube->fetchVideoViewCounts($videoIds);

        $bestId    = null;
        $bestViews = $minViews - 1;

        foreach ($results as $result) {
            $views = $viewCounts[$result['videoId']] ?? 0;
            if ($views > $bestViews) {
                $bestViews = $views;
                $bestId    = $result['videoId'];
            }
        }

        return $bestId;
    }

    private function buildQuestion(string $movieTitle, string $videoId): ?Question
    {
        $filename = sprintf('film-%s.mp4', md5($videoId));
        $webPath  = $this->downloader->download($videoId, $filename, self::CLIP_START, self::CLIP_DURATION);

        if ($webPath === null) {
            $this->debug(sprintf('Download failed: %s', $videoId));
            return null;
        }

        return new Question(
            id:        md5($videoId . $this->lang),
            category:  self::CATEGORY_ID,
            type:      QuestionType::FilmScene,
            question:  self::QUESTIONS[$this->lang] ?? self::QUESTIONS['de'],
            answer:    $movieTitle,
            videoPath: $webPath,
        );
    }
}
