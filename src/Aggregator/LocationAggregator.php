<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Model\QuestionType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LocationAggregator implements AggregatorInterface
{
    private const CATEGORY_ID = 'location';
    private const IMAGE_WIDTH = 800;

    /**
     * No ORDER BY — avoids always returning the same top-N by sitelinks, which clusters
     * heavily around Iraq/Italy/Egypt due to ancient-site Wikipedia coverage.
     * The sitelinks FILTER still acts as a quality gate.
     */
    private const SPARQL_QUERY = <<<'SPARQL'
SELECT DISTINCT ?item ?itemLabel ?image ?deLabel ?countryLabel ?countryDeLabel ?coord ?sitelinks WHERE {
  VALUES ?type { wd:Q570116 wd:Q4989906 wd:Q839954 wd:Q4790 }
  ?item wdt:P31 ?type ;
        wdt:P625 ?coord ;
        wdt:P18 ?image ;
        wikibase:sitelinks ?sitelinks .
  FILTER(?sitelinks >= %d)
  OPTIONAL { ?item rdfs:label ?deLabel . FILTER(LANG(?deLabel) = "de") }
  OPTIONAL {
    ?item wdt:P17 ?country .
    ?country rdfs:label ?countryLabel . FILTER(LANG(?countryLabel) = "en")
    OPTIONAL { ?country rdfs:label ?countryDeLabel . FILTER(LANG(?countryDeLabel) = "de") }
  }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en" . }
}
LIMIT %d
SPARQL;
    private const QUESTIONS = [
        'de' => 'Wo auf der Welt befindet sich dieser Ort?',
        'en' => 'Where in the world is this location?',
    ];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $imagesDir,
        private readonly string $wikidataSparqlUrl,
        private readonly string $lang = 'de',
        private readonly int $wikidataMinSitelinks = 50,
        private readonly int $wikidataFetchLimit = 300,
        private readonly int $maxPerCountry = 3,
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
        $this->ensureImagesDir();

        $landmarks = $this->fetchFromWikidata($this->wikidataFetchLimit);
        if (empty($landmarks)) {
            return [];
        }

        $selected = array_slice(
            $this->applyGeographicDiversity($landmarks),
            0,
            min($count, count($landmarks))
        );

        $questions = [];
        foreach ($selected as $landmark) {
            $question = $this->fetchLandmark($landmark);
            if ($question !== null) {
                $questions[] = $question;
            }
            usleep(500_000);
        }

        return $questions;
    }

    /**
     * Shuffles the pool and caps results per country to avoid geographic clustering.
     *
     * @return array<array{title: string, imageUrl: string, country: string, en: string, de: string, lat: float, lng: float}>
     */
    private function applyGeographicDiversity(array $landmarks): array
    {
        shuffle($landmarks);

        $countPerCountry = [];
        $diverse         = [];

        foreach ($landmarks as $landmark) {
            $country = $landmark['country'];
            $currentCount = $countPerCountry[$country] ?? 0;

            if ($currentCount < $this->maxPerCountry) {
                $countPerCountry[$country] = $currentCount + 1;
                $diverse[] = $landmark;
                $this->debug(sprintf('Keep:  %s (%s) [%d/%d]', $landmark['title'], $country ?: 'unknown', $currentCount + 1, $this->maxPerCountry));
            } else {
                $this->debug(sprintf('Skip:  %s (%s) — country cap reached', $landmark['title'], $country ?: 'unknown'));
            }
        }

        return $diverse;
    }

    /** @return array<array{title: string, imageUrl: string, country: string, en: string, de: string, lat: float, lng: float}> */
    private function fetchFromWikidata(int $fetchLimit): array
    {
        $query = sprintf(self::SPARQL_QUERY, $this->wikidataMinSitelinks, $fetchLimit);

        try {
            $response = $this->client->request('GET', $this->wikidataSparqlUrl, [
                'query' => ['query' => $query, 'format' => 'json'],
                'headers' => [
                    'Accept'     => 'application/sparql-results+json',
                    'User-Agent' => 'ClaudeQuiz/1.0 (quiz aggregation bot)',
                ],
                'timeout' => 30,
            ]);
            $data = $response->toArray();
        } catch (\Throwable) {
            return [];
        }

        return $this->parseWikidataResults($data['results']['bindings'] ?? []);
    }

    /** @return array<array{title: string, imageUrl: string, country: string, en: string, de: string, lat: float, lng: float}> */
    private function parseWikidataResults(array $bindings): array
    {
        $seen      = [];
        $landmarks = [];

        foreach ($bindings as $row) {
            $itemId = $row['item']['value'] ?? '';
            if (isset($seen[$itemId])) {
                continue;
            }

            $landmark = $this->parseLandmarkRow($row);
            if ($landmark !== null) {
                $seen[$itemId] = true;
                $landmarks[]   = $landmark;
                $this->debug(sprintf('Found: %s (%s)', $landmark['title'], $landmark['country'] ?: 'unknown country'));
            }
        }

        return $landmarks;
    }

    /** @return array{title: string, imageUrl: string, country: string, en: string, de: string, lat: float, lng: float}|null */
    private function parseLandmarkRow(array $row): ?array
    {
        $enLabel  = $row['itemLabel']['value'] ?? null;
        $imageUrl = $row['image']['value'] ?? null;
        $coord    = $row['coord']['value'] ?? null;

        if ($enLabel === null || $imageUrl === null || $coord === null) {
            return null;
        }

        if (!preg_match('/Point\((-?\d+\.?\d*)\s+(-?\d+\.?\d*)\)/i', $coord, $matches)) {
            return null;
        }

        $lng       = (float) $matches[1];
        $lat       = (float) $matches[2];
        $deLabel   = $row['deLabel']['value'] ?? $enLabel;
        $countryEn = $row['countryLabel']['value'] ?? null;
        $countryDe = $row['countryDeLabel']['value'] ?? $countryEn;

        return [
            'title'    => $enLabel,
            'imageUrl' => $imageUrl . '?width=' . self::IMAGE_WIDTH,
            'country'  => $countryEn ?? '',
            'en'       => $countryEn !== null ? "$enLabel, $countryEn" : $enLabel,
            'de'       => $countryDe !== null ? "$deLabel, $countryDe" : $deLabel,
            'lat'      => $lat,
            'lng'      => $lng,
        ];
    }

    private function fetchLandmark(array $landmark): ?Question
    {
        $filename  = $this->buildFilename($landmark['title']);
        $imagePath = $this->imagesDir . '/' . $filename;

        if (!file_exists($imagePath) && !$this->downloadImage($landmark['imageUrl'], $imagePath)) {
            $this->debug(sprintf('No image: %s', $landmark['title']));
            return null;
        }

        return new Question(
            id: md5($landmark['title'] . $this->lang),
            category: self::CATEGORY_ID,
            type: QuestionType::LocationGuess,
            question: self::QUESTIONS[$this->lang] ?? self::QUESTIONS['de'],
            answer: $landmark[$this->lang] ?? $landmark['en'],
            imagePath: 'images/' . $filename,
            latitude: $landmark['lat'],
            longitude: $landmark['lng'],
        );
    }

    private function downloadImage(string $url, string $path): bool
    {
        try {
            $response = $this->client->request('GET', $url);
            file_put_contents($path, $response->getContent());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildFilename(string $title): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));

        return sprintf('location-%s.jpg', trim($slug, '-'));
    }

    private function ensureImagesDir(): void
    {
        if (!is_dir($this->imagesDir)) {
            mkdir($this->imagesDir, 0755, true);
        }
    }
}
