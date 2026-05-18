<?php

declare(strict_types=1);

namespace App\Aggregator;

use App\Model\Question;
use App\Model\QuestionType;
use App\Provider\ImageProviderInterface;

final class ImageRevealAggregator implements AggregatorInterface
{
    private const CATEGORY_ID = 'image_reveal';
    private const QUESTIONS = [
        'de' => 'Was ist auf dem Bild zu sehen?',
        'en' => 'What can you see in the image?',
    ];

    private const SUBJECTS = [
        // Animals - common species
        ['title' => 'Lion',                      'de' => 'Löwe',               'en' => 'Lion'],
        ['title' => 'African elephant',          'de' => 'Elefant',            'en' => 'Elephant'],
        ['title' => 'Bengal tiger',              'de' => 'Tiger',              'en' => 'Tiger'],
        ['title' => 'Giraffe',                   'de' => 'Giraffe',            'en' => 'Giraffe'],
        ['title' => 'Hippopotamus',              'de' => 'Nilpferd',           'en' => 'Hippopotamus'],
        ['title' => 'White rhinoceros',          'de' => 'Nashorn',            'en' => 'Rhinoceros'],
        ['title' => 'Zebra',                     'de' => 'Zebra',              'en' => 'Zebra'],
        ['title' => 'Gorilla',                   'de' => 'Gorilla',            'en' => 'Gorilla'],
        ['title' => 'Chimpanzee',                'de' => 'Schimpanse',         'en' => 'Chimpanzee'],
        ['title' => 'Koala',                     'de' => 'Koala',              'en' => 'Koala'],
        ['title' => 'Giant panda',               'de' => 'Panda',              'en' => 'Giant Panda'],
        ['title' => 'Polar bear',                'de' => 'Eisbär',             'en' => 'Polar Bear'],
        ['title' => 'Wolf',                      'de' => 'Wolf',               'en' => 'Wolf'],
        ['title' => 'Red fox',                   'de' => 'Rotfuchs',           'en' => 'Red Fox'],
        ['title' => 'Domestic dog',              'de' => 'Hund',               'en' => 'Dog'],
        ['title' => 'Domestic cat',              'de' => 'Katze',              'en' => 'Cat'],
        ['title' => 'Rabbit',                    'de' => 'Kaninchen',          'en' => 'Rabbit'],
        ['title' => 'Horse',                     'de' => 'Pferd',              'en' => 'Horse'],
        ['title' => 'Cow',                       'de' => 'Kuh',                'en' => 'Cow'],
        ['title' => 'Sheep',                     'de' => 'Schaf',              'en' => 'Sheep'],
        ['title' => 'Pig',                       'de' => 'Schwein',            'en' => 'Pig'],
        ['title' => 'Duck',                      'de' => 'Ente',               'en' => 'Duck'],
        ['title' => 'Goose',                     'de' => 'Gans',               'en' => 'Goose'],
        ['title' => 'Chicken',                   'de' => 'Huhn',               'en' => 'Chicken'],
        ['title' => 'Penguin',                   'de' => 'Pinguin',            'en' => 'Penguin'],
        ['title' => 'Eagle',                     'de' => 'Adler',              'en' => 'Eagle'],
        ['title' => 'Owl',                       'de' => 'Eule',               'en' => 'Owl'],
        ['title' => 'Parrot',                    'de' => 'Papagei',            'en' => 'Parrot'],
        ['title' => 'Swan',                      'de' => 'Schwan',             'en' => 'Swan'],
        ['title' => 'Peacock',                   'de' => 'Pfau',               'en' => 'Peacock'],
        ['title' => 'Flamingo',                  'de' => 'Flamingo',           'en' => 'Flamingo'],
        ['title' => 'Toucan',                    'de' => 'Tukan',              'en' => 'Toucan'],
        ['title' => 'Crocodile',                 'de' => 'Krokodil',           'en' => 'Crocodile'],
        ['title' => 'Snake',                     'de' => 'Schlange',           'en' => 'Snake'],
        ['title' => 'Turtle',                    'de' => 'Schildkröte',        'en' => 'Turtle'],
        ['title' => 'Frog',                      'de' => 'Frosch',             'en' => 'Frog'],
        ['title' => 'Fish',                      'de' => 'Fisch',              'en' => 'Fish'],
        ['title' => 'Dolphin',                   'de' => 'Delfin',             'en' => 'Dolphin'],
        ['title' => 'Whale',                     'de' => 'Wal',                'en' => 'Whale'],
        ['title' => 'Shark',                     'de' => 'Hai',                'en' => 'Shark'],
        ['title' => 'Starfish',                  'de' => 'Seestern',           'en' => 'Starfish'],
        ['title' => 'Jellyfish',                 'de' => 'Qualle',             'en' => 'Jellyfish'],
        ['title' => 'Octopus',                   'de' => 'Tintenfisch',        'en' => 'Octopus'],
        ['title' => 'Butterfly',                 'de' => 'Schmetterling',      'en' => 'Butterfly'],
        ['title' => 'Bee',                       'de' => 'Biene',              'en' => 'Bee'],
        ['title' => 'Ant',                       'de' => 'Ameise',             'en' => 'Ant'],
        ['title' => 'Spider',                    'de' => 'Spinne',             'en' => 'Spider'],
        ['title' => 'Scorpion',                  'de' => 'Skorpion',           'en' => 'Scorpion'],
        ['title' => 'Cheetah',                   'de' => 'Gepard',             'en' => 'Cheetah'],
        ['title' => 'Leopard',                   'de' => 'Leopard',            'en' => 'Leopard'],
        ['title' => 'Jaguar',                    'de' => 'Jaguar',             'en' => 'Jaguar'],
        ['title' => 'Cougar',                    'de' => 'Puma',               'en' => 'Cougar'],
        ['title' => 'Hyena',                     'de' => 'Hyäne',              'en' => 'Hyena'],
        ['title' => 'Meerkat',                   'de' => 'Erdmännchen',        'en' => 'Meerkat'],
        ['title' => 'Squirrel',                  'de' => 'Eichhörnchen',       'en' => 'Squirrel'],
        ['title' => 'Mouse',                     'de' => 'Maus',               'en' => 'Mouse'],
        ['title' => 'Rat',                       'de' => 'Ratte',              'en' => 'Rat'],
        ['title' => 'Hedgehog',                  'de' => 'Igel',               'en' => 'Hedgehog'],
        ['title' => 'Bat',                       'de' => 'Fledermaus',         'en' => 'Bat'],
        ['title' => 'Monkey',                    'de' => 'Affe',               'en' => 'Monkey'],
        ['title' => 'Zebra finch',               'de' => 'Zebrafink',          'en' => 'Zebra Finch'],
        ['title' => 'Porcupine',                 'de' => 'Stachelschwein',     'en' => 'Porcupine'],
        ['title' => 'Otter',                     'de' => 'Otter',              'en' => 'Otter'],
        ['title' => 'Seal',                      'de' => 'Seehund',            'en' => 'Seal'],
        ['title' => 'Elk',                       'de' => 'Elch',               'en' => 'Elk'],
        ['title' => 'Moose',                     'de' => 'Elch',               'en' => 'Moose'],
        ['title' => 'Deer',                      'de' => 'Reh',                'en' => 'Deer'],
        ['title' => 'Antelope',                  'de' => 'Antilope',           'en' => 'Antelope'],
        // Objects
        ['title' => 'Car',                       'de' => 'Auto',               'en' => 'Car'],
        ['title' => 'Bicycle',                   'de' => 'Fahrrad',            'en' => 'Bicycle'],
        ['title' => 'Motorcycle',                'de' => 'Motorrad',           'en' => 'Motorcycle'],
        ['title' => 'Train',                     'de' => 'Zug',                'en' => 'Train'],
        ['title' => 'Airplane',                  'de' => 'Flugzeug',           'en' => 'Airplane'],
        ['title' => 'Helicopter',                'de' => 'Hubschrauber',       'en' => 'Helicopter'],
        ['title' => 'Boat',                      'de' => 'Boot',               'en' => 'Boat'],
        ['title' => 'Ship',                      'de' => 'Schiff',             'en' => 'Ship'],
        ['title' => 'House',                     'de' => 'Haus',               'en' => 'House'],
        ['title' => 'Building',                  'de' => 'Gebäude',            'en' => 'Building'],
        ['title' => 'Bridge',                    'de' => 'Brücke',             'en' => 'Bridge'],
        ['title' => 'Lighthouse',                'de' => 'Leuchtturm',         'en' => 'Lighthouse'],
        ['title' => 'Clock',                     'de' => 'Uhr',                'en' => 'Clock'],
        ['title' => 'Watch',                     'de' => 'Armbanduhr',         'en' => 'Watch'],
        ['title' => 'Telephone',                 'de' => 'Telefon',            'en' => 'Telephone'],
        ['title' => 'Camera',                    'de' => 'Kamera',             'en' => 'Camera'],
        ['title' => 'Television',                'de' => 'Fernseher',          'en' => 'Television'],
        ['title' => 'Computer',                  'de' => 'Computer',           'en' => 'Computer'],
        ['title' => 'Keyboard',                  'de' => 'Tastatur',           'en' => 'Keyboard'],
        ['title' => 'Computer mouse',             'de' => 'Computermaus',       'en' => 'Computer Mouse'],
        ['title' => 'Book',                      'de' => 'Buch',               'en' => 'Book'],
        ['title' => 'Newspaper',                 'de' => 'Zeitung',            'en' => 'Newspaper'],
        ['title' => 'Pencil',                    'de' => 'Stift',              'en' => 'Pencil'],
        ['title' => 'Pen',                       'de' => 'Feder',              'en' => 'Pen'],
        ['title' => 'Paper',                     'de' => 'Papier',             'en' => 'Paper'],
        ['title' => 'Envelope',                  'de' => 'Umschlag',           'en' => 'Envelope'],
        ['title' => 'Cup',                       'de' => 'Tasse',              'en' => 'Cup'],
        ['title' => 'Plate',                     'de' => 'Teller',             'en' => 'Plate'],
        ['title' => 'Fork',                      'de' => 'Gabel',              'en' => 'Fork'],
        ['title' => 'Knife',                     'de' => 'Messer',             'en' => 'Knife'],
        ['title' => 'Spoon',                     'de' => 'Löffel',             'en' => 'Spoon'],
        ['title' => 'Bottle',                    'de' => 'Flasche',            'en' => 'Bottle'],
        ['title' => 'Glass',                     'de' => 'Glas',               'en' => 'Glass'],
        ['title' => 'Knife',                     'de' => 'Messer',             'en' => 'Knife'],
        ['title' => 'Chair',                     'de' => 'Stuhl',              'en' => 'Chair'],
        ['title' => 'Table',                     'de' => 'Tisch',              'en' => 'Table'],
        ['title' => 'Desk',                      'de' => 'Schreibtisch',       'en' => 'Desk'],
        ['title' => 'Bed',                       'de' => 'Bett',               'en' => 'Bed'],
        ['title' => 'Door',                      'de' => 'Tür',                'en' => 'Door'],
        ['title' => 'Window',                    'de' => 'Fenster',            'en' => 'Window'],
        ['title' => 'Tree',                      'de' => 'Baum',               'en' => 'Tree'],
        ['title' => 'Flower',                    'de' => 'Blume',              'en' => 'Flower'],
        ['title' => 'Rose',                      'de' => 'Rose',               'en' => 'Rose'],
        ['title' => 'Sun',                       'de' => 'Sonne',              'en' => 'Sun'],
        ['title' => 'Moon',                      'de' => 'Mond',               'en' => 'Moon'],
        ['title' => 'Star',                      'de' => 'Stern',              'en' => 'Star'],
        ['title' => 'Mountain',                  'de' => 'Berg',               'en' => 'Mountain'],
        ['title' => 'Ocean',                     'de' => 'Ozean',              'en' => 'Ocean'],
        ['title' => 'Beach',                     'de' => 'Strand',             'en' => 'Beach'],
        ['title' => 'Desert',                    'de' => 'Wüste',              'en' => 'Desert'],
        ['title' => 'Forest',                    'de' => 'Wald',               'en' => 'Forest'],
        ['title' => 'Waterfall',                 'de' => 'Wasserfall',         'en' => 'Waterfall'],
    ];

    public function __construct(
        private readonly ImageProviderInterface $imageProvider,
        private readonly string $lang = 'de',
        private readonly ?\Closure $debugLogger = null,
        private readonly ?WordlistReader $wordlistReader = null,
        private readonly bool $randomWords = false,
    ) {}

    public function getCategoryId(): string
    {
        return self::CATEGORY_ID;
    }

    /** @return Question[] */
    public function fetch(int $count): array
    {
        $subjects = $this->resolveSubjects($count);
        $questions = [];
        $index     = 0;
        $interval  = $this->imageProvider->getMinRequestInterval();

        while (count($questions) < $count && $index < count($subjects)) {
            $question = $this->fetchSubject($subjects[$index]);
            if ($question !== null) {
                $questions[] = $question;
            }
            $index++;
            usleep($interval);
        }

        return $questions;
    }

    /** @return array<array{title: string, de: string, en: string}> */
    private function resolveSubjects(int $count): array
    {
        if ($this->wordlistReader === null) {
            $subjects = self::SUBJECTS;
            shuffle($subjects);
            return $subjects;
        }

        // Fetch a buffer larger than needed to account for failed image lookups
        $buffer = $this->randomWords
            ? $this->wordlistReader->readRandom($count * 3)
            : $this->wordlistReader->readSequential($count * 3);

        return array_map(
            fn(string $word) => ['title' => $word, 'de' => $word, 'en' => $word],
            $buffer,
        );
    }

    private function fetchSubject(array $subject): ?Question
    {
        $imageUrl = $this->imageProvider->fetchImage($subject['title']);
        if ($imageUrl === null) {
            $this->debug("No image found for '{$subject['title']}'");
            return null;
        }

        return new Question(
            id: md5($subject['title'] . $this->lang),
            category: self::CATEGORY_ID,
            type: QuestionType::ImageReveal,
            question: self::QUESTIONS[$this->lang] ?? self::QUESTIONS['de'],
            answer: $subject[$this->lang] ?? $subject['de'],
            imagePath: $imageUrl,
        );
    }

    private function debug(string $message): void
    {
        if ($this->debugLogger) {
            ($this->debugLogger)($message);
        }
    }


}
