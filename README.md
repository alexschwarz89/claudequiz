# ClaudeQuiz

ClaudeQuiz is a local two-player quiz game that runs entirely in the browser with a lightweight PHP backend. Two players share a screen, take turns answering questions across six categories, and whoever scores the most wins. Content is aggregated from free public APIs and stored as flat JSON — no database required.

## Requirements

- PHP 8.2+
- [Composer](https://getcomposer.org/)
- A modern web browser

## Installation

```bash
git clone https://github.com/alexschwarz89/claudequiz.git
cd claudequiz
composer install
cp .env.dist .env
```

Edit `.env` to configure your question provider and any required API keys (see below).

---

## Quick Start

### Minimum configuration (no API keys required)

```env
QUIZ_PROVIDER=API
IMAGE_PROVIDER=wikimedia
WORDLIST_PATH=data/ki_wordlist_v2.txt
```

This uses [OpenTrivia](https://opentdb.com) for true/false and multiple-choice questions (free, no key) and Wikimedia for image-reveal images (free, no key). `WORDLIST_PATH` is required for the image-reveal category — without it no image questions are generated. Flag questions are excluded by default.

### Aggregate content

Populate `data/questions.json` before starting the server:

```bash
php bin/console aggregate:content
```

### Start the development server

```bash
php -S localhost:8080 -t public/ public/index.php
```

Open [http://localhost:8080](http://localhost:8080), enter player names, and start playing.

---

## API Keys

API keys are only needed for specific providers. All others work without authentication.

| Variable | When required | Where to get it |
|---|---|---|
| `ANTHROPIC_API_KEY` | `QUIZ_PROVIDER=WIKIPEDIA` | [console.anthropic.com](https://console.anthropic.com) |
| `PIXABAY_API_KEY` | `IMAGE_PROVIDER=pixabay` | [pixabay.com/api/docs](https://pixabay.com/api/docs/) |
| `DEEPL_API_KEY` | Optional — translates API questions to non-English languages | [deepl.com/pro-api](https://www.deepl.com/pro-api) |

---

## Aggregation Options

```bash
# Default: all categories, German, 20 questions each
php bin/console aggregate:content

# English questions
php bin/console aggregate:content --lang=en

# Limit to specific categories
php bin/console aggregate:content --categories=true_false --categories=multiple_choice

# Fetch more questions per category
php bin/console aggregate:content --count=50

# Include flag_mc (excluded by default — downloads ~100 flag images)
php bin/console aggregate:content --generate-flags

# Clear all cached images and re-download
php bin/console aggregate:content --clear-images

# Verbose provider output
php bin/console aggregate:content --debug
```

---

## Environment Variables

Copy `.env.dist` to `.env`. All variables have defaults; only API keys are sensitive.

### Question provider

| Variable | Default | Description |
|---|---|---|
| `QUIZ_PROVIDER` | `API` | Source for true/false and multiple-choice questions: `API`, `WIKIPEDIA`, or `FILE` |
| `OPENTRIVIA_URL_DE` | `https://api.opentrivia.de` | German OpenTrivia endpoint (used when `QUIZ_PROVIDER=API`, `--lang=de`) |
| `OPENTRIVIA_URL_EN` | `https://opentdb.com/api.php` | English OpenTrivia endpoint (used when `QUIZ_PROVIDER=API`, `--lang=en`) |
| `OPENTRIVIA_CATEGORIES` | `9,10,11,...` | Comma-separated OpenTrivia category IDs to draw from |
| `ANTHROPIC_API_KEY` | _(empty)_ | Required when `QUIZ_PROVIDER=WIKIPEDIA` |
| `ANTHROPIC_API_URL` | `https://api.anthropic.com/v1/messages` | Anthropic API endpoint |
| `WIKIPEDIA_EXCLUDE_TOPICS_DE` | _(see .env.dist)_ | Comma-separated German Wikipedia category keywords to skip (e.g. `Geschichte,Sport,Kunst`). Browse category names at [de.wikipedia.org/wiki/Kategorie:Wikipedia:Exzellent](https://de.wikipedia.org/wiki/Kategorie:Wikipedia:Exzellent) |
| `WIKIPEDIA_EXCLUDE_TOPICS_EN` | _(see .env.dist)_ | Comma-separated English Wikipedia category keywords to skip (e.g. `history,sport,art`). Browse at [en.wikipedia.org/wiki/Category:Featured_articles](https://en.wikipedia.org/wiki/Category:Featured_articles) |
| `QUESTION_FILE_PATH` | `data/custom_questions.json` | Path to custom question file when `QUIZ_PROVIDER=FILE` |
| `DEEPL_API_KEY` | _(empty)_ | Optional — enables translation when `--lang` is not `en` |
| `DEEPL_API_URL_FREE` | `https://api-free.deepl.com/v2/translate` | DeepL Free endpoint |
| `DEEPL_API_URL_PRO` | `https://api.deepl.com/v2/translate` | DeepL Pro endpoint |

### Image reveal

| Variable | Default | Description |
|---|---|---|
| `IMAGE_PROVIDER` | `pixabay` | Image source: `pixabay` (fast, requires key) or `wikimedia` (slower, no key) |
| `PIXABAY_API_KEY` | _(empty)_ | Required when `IMAGE_PROVIDER=pixabay` |
| `PIXABAY_API_URL` | `https://pixabay.com/api/` | Pixabay API endpoint |
| `WIKIMEDIA_API_URL` | `https://en.wikipedia.org/w/api.php` | Wikimedia API endpoint |
| `WIKIMEDIA_USER_AGENT` | _(see .env.dist)_ | User-Agent for all Wikimedia requests. Must include a contact URL or email per the [Wikimedia User-Agent policy](https://meta.wikimedia.org/wiki/User-Agent_policy) |
| `WORDLIST_PATH` | _(empty)_ | **Required** for image-reveal. Path to a word list file (relative to project root or absolute). Without this, no image-reveal questions are generated |
| `WORDLIST_FORMAT` | `newline` | `newline` (one word per line) or `csv` (comma-separated) |

### Location category

| Variable | Default | Description |
|---|---|---|
| `WIKIDATA_SPARQL_URL` | `https://query.wikidata.org/sparql` | Wikidata SPARQL endpoint |
| `WIKIDATA_MIN_SITELINKS` | `50` | Minimum Wikipedia language editions a landmark must have |
| `WIKIDATA_FETCH_LIMIT` | `300` | Maximum candidates fetched before filtering |
| `LOCATION_MAX_PER_COUNTRY` | `3` | Maximum landmarks per country (prevents geographic clustering) |

### Flag category

| Variable | Default | Description |
|---|---|---|
| `REST_COUNTRIES_BASE_URL` | `https://restcountries.com/v3.1` | REST Countries API base URL |
| `FLAG_SCOPE` | `world` | `europe` or `world` |
| `FLAG_MIN_POPULATION_EUROPE` | `1000000` | Minimum population for European countries |
| `FLAG_MIN_POPULATION_WORLD` | `10000000` | Minimum population for world scope |

### Song category

| Variable | Default | Description |
|---|---|---|
| `DEEZER_CHART_URL` | `https://api.deezer.com/chart/0/tracks` | Deezer chart endpoint for song previews |

---

## Custom Question File (FILE provider)

Set `QUIZ_PROVIDER=FILE` and point `QUESTION_FILE_PATH` to a JSON file structured as follows:

```json
{
  "true_false": [
    { "statement": "The Eiffel Tower is in Paris.", "is_true": true }
  ],
  "multiple_choice": [
    {
      "question": "What is the capital of Japan?",
      "answer": "Tokyo",
      "options": ["Seoul", "Beijing", "Bangkok"]
    }
  ]
}
```

See `data/custom_questions.json` for a working example.

---

## Categories

| ID | Name | Mechanic | Content source |
|---|---|---|---|
| `true_false` | True or False | A statement is displayed; players vote true or false. Self-scored. | OpenTrivia, Wikipedia, or custom file |
| `multiple_choice` | Multiple Choice | A question with four answer options. Self-scored. | OpenTrivia, Wikipedia, or custom file |
| `song_guess` | Song Guess | A 30-second Deezer preview plays; players guess artist or title. Self-scored after 15 s. | Deezer chart API |
| `image_reveal` | Image Reveal | An image hidden behind a 24×18 tile grid uncovers progressively over 20 seconds; players guess the subject. Self-scored. | Pixabay or Wikimedia |
| `location` | Location | A landmark photo is shown; players click on a world map where they think it is. Score depends on distance accuracy. | Wikidata + Wikipedia images |
| `flag_mc` | Flag Quiz | A country flag is shown alongside two decoy flags; players choose the correct country name. | REST Countries API |

Flag aggregation is excluded from the default `aggregate:content` run because it downloads ~100 PNG images. Pass `--generate-flags` to include it.

---

## Maintenance Commands

```bash
# Print question statistics per category
php bin/console stats

# Remove questions previously reported as incorrect
php bin/console purge:reported-questions

# Clean up stale multiplayer room files
php bin/console cleanup:rooms

# Deduplicate and validate the question database
php bin/console cleanup:questions
```

---

## Project Structure

```
bin/console              CLI entry point
data/
  custom_questions.json  Example FILE-provider question set
  ki_wordlist_v2.txt     German word list for image reveal
public/
  index.php              HTTP router
  js/game.js             Vanilla JS single-page game loop
  css/game.css           Styles
  images/                Generated flag and location images
  audio/songs/           Generated song preview MP3s
src/
  Aggregator/            One aggregator per category
  Command/               Symfony Console commands
  Config/                .env loader
  Http/                  HTTP client decorators (e.g. WikimediaHttpClient)
  Model/                 Question model and type enum
  Provider/              Text question providers (API, Wikipedia, File)
  Repository/            JSON file persistence layer
  Room/                  Multiplayer room state management
  Translator/            DeepL translation wrapper
```

---

## License

MIT
