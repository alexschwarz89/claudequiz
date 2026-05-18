<?php

declare(strict_types=1);

namespace App\Room;

use App\Repository\QuestionRepository;

final class RoomService
{
    private const REVEAL_SECONDS        = 4;
    private const QUESTION_SECONDS      = 30;
    private const KOPFRECHNEN_SECONDS   = 120;

    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly QuestionRepository $questions,
    ) {}

    public function create(string $playerName, array $categories, int $count): array
    {
        $code     = $this->rooms->generateCode();
        $playerId = $this->generateId();

        $room = [
            'code'               => $code,
            'phase'              => 'waiting',
            'host'               => ['id' => $playerId, 'name' => $playerName, 'score' => 0, 'streak' => 0],
            'guest'              => null,
            'questions'          => $this->pickQuestions($categories, $count),
            'questionIndex'      => 0,
            'answers'            => [],
            'revealAt'           => null,
            'questionStartedAt'  => null,
            'settings'           => ['categories' => $categories, 'count' => $count],
            'created'            => time(),
        ];

        $this->rooms->save($room);

        return ['code' => $code, 'playerId' => $playerId];
    }

    public function join(string $code, string $playerName): array
    {
        $room = $this->rooms->load($code);

        if ($room === null)               return $this->error('Raum nicht gefunden');
        if ($room['guest'] !== null)      return $this->error('Raum ist voll');
        if ($room['phase'] !== 'waiting') return $this->error('Spiel bereits gestartet');

        $playerId      = $this->generateId();
        $room['guest'] = ['id' => $playerId, 'name' => $playerName, 'score' => 0, 'streak' => 0];
        $room['phase'] = 'ready';

        $this->rooms->save($room);

        return ['code' => $code, 'playerId' => $playerId];
    }

    public function start(string $code, string $playerId): array
    {
        $room = $this->rooms->load($code);

        if ($room === null)                     return $this->error('Raum nicht gefunden');
        if ($room['host']['id'] !== $playerId)  return $this->error('Nur der Host kann starten');
        if ($room['phase'] !== 'ready')         return $this->error('Spiel nicht bereit');

        $room['phase']             = 'question';
        $room['questionStartedAt'] = time();
        $this->rooms->save($room);

        return $room;
    }

    public function answer(string $code, string $playerId, bool $correct, int $solved = 0): array
    {
        $room = $this->rooms->load($code);

        if ($room === null)                return $this->error('Raum nicht gefunden');
        if ($room['phase'] !== 'question') return $this->error('Nicht in Fragerunde');

        $role = $this->roleOf($room, $playerId);
        if ($role === null)                return $this->error('Spieler nicht im Raum');
        if (isset($room['answers'][$role])) return $room; // idempotent

        $isKopfrechnen = ($room['questions'][$room['questionIndex']]['type'] ?? '') === 'kopfrechnen';

        if ($isKopfrechnen) {
            $room['answers'][$role]   = $solved;
            $room[$role]['score']    += $solved;
        } else {
            $room['answers'][$role] = $correct;
            if ($correct) {
                $room[$role]['score']++;
                $room[$role]['streak']++;
            } else {
                $room[$role]['streak'] = 0;
            }
        }

        $bothAnswered = isset($room['answers']['host'], $room['answers']['guest']);
        if ($bothAnswered) {
            $room['phase']    = 'reveal';
            $room['revealAt'] = time();
        }

        $this->rooms->save($room);

        return $room;
    }

    public function poll(string $code): ?array
    {
        $room = $this->rooms->load($code);
        if ($room === null) {
            return null;
        }

        if ($room['phase'] === 'question' && $this->questionTimedOut($room)) {
            $room = $this->autoTimeout($room);
        }

        if ($room['phase'] === 'reveal' && $this->revealExpired($room)) {
            $room = $this->advance($room);
        }

        return $room;
    }

    private function questionTimedOut(array $room): bool
    {
        if ($room['questionStartedAt'] === null) {
            return false;
        }
        $question  = $room['questions'][$room['questionIndex']] ?? [];
        $timeLimit = ($question['type'] ?? '') === 'kopfrechnen'
            ? self::KOPFRECHNEN_SECONDS
            : self::QUESTION_SECONDS;

        return time() >= $room['questionStartedAt'] + $timeLimit;
    }

    private function autoTimeout(array $room): array
    {
        $question      = $room['questions'][$room['questionIndex']] ?? [];
        $isKopfrechnen = ($question['type'] ?? '') === 'kopfrechnen';

        foreach (['host', 'guest'] as $role) {
            if (isset($room['answers'][$role])) {
                continue;
            }
            if ($isKopfrechnen) {
                $room['answers'][$role] = 0;
            } else {
                $room['answers'][$role] = false;
                $room[$role]['streak']  = 0;
            }
        }

        $room['phase']    = 'reveal';
        $room['revealAt'] = time();

        $this->rooms->save($room);

        return $room;
    }

    private function revealExpired(array $room): bool
    {
        return $room['revealAt'] !== null
            && time() >= $room['revealAt'] + self::REVEAL_SECONDS;
    }

    private function advance(array $room): array
    {
        $room['questionIndex']++;
        $room['answers']  = [];
        $room['revealAt'] = null;
        $room['phase']    = $room['questionIndex'] >= count($room['questions'])
            ? 'finished'
            : 'question';
        $room['questionStartedAt'] = $room['phase'] === 'question' ? time() : null;

        $this->rooms->save($room);

        return $room;
    }

    public function restart(string $code, string $playerId): array
    {
        $room = $this->rooms->load($code);

        if ($room === null)                           return $this->error('Raum nicht gefunden');
        if ($this->roleOf($room, $playerId) === null) return $this->error('Spieler nicht im Raum');
        if ($room['phase'] === 'ready')               return $room;
        if ($room['phase'] !== 'finished')            return $this->error('Spiel noch nicht beendet');

        $settings = $room['settings'] ?? ['categories' => [], 'count' => count($room['questions'])];

        $room['phase']             = 'ready';
        $room['questionIndex']     = 0;
        $room['answers']           = [];
        $room['revealAt']          = null;
        $room['questionStartedAt'] = null;
        $room['questions']         = $this->pickQuestions($settings['categories'], $settings['count']);
        $room['host']['score']     = 0;
        $room['host']['streak']    = 0;
        $room['guest']['score']    = 0;
        $room['guest']['streak']   = 0;

        $this->rooms->save($room);

        return $room;
    }

    private function pickQuestions(array $categories, int $count): array
    {
        $withoutKr = array_values(array_filter($categories, fn($c) => $c !== 'kopfrechnen'));
        $includeKr = !empty($categories) && count($withoutKr) < count($categories);

        $all = array_values(array_filter(
            array_map(fn($q) => $q->toArray(), $this->questions->load()),
            fn($q) => empty($withoutKr) || in_array($q['category'], $withoutKr, true)
        ));

        if (!$includeKr) {
            shuffle($all);
            return array_slice($all, 0, $count);
        }

        $numCats = max(1, count($categories));
        $krCount = max(1, (int) round($count / $numCats));
        $dbCount = max(0, $count - $krCount);

        shuffle($all);
        $selected = array_slice($all, 0, $dbCount);

        for ($i = 0; $i < $krCount; $i++) {
            $selected[] = $this->syntheticKopfrechnen($i);
        }

        shuffle($selected);

        return $selected;
    }

    private function syntheticKopfrechnen(int $index): array
    {
        return [
            'id'        => "kopfrechnen-{$index}",
            'category'  => 'kopfrechnen',
            'type'      => 'kopfrechnen',
            'question'  => 'Kopfrechnen',
            'answer'    => '',
            'options'   => null,
            'imagePath' => null,
            'audioUrl'  => null,
            'latitude'  => null,
            'longitude' => null,
        ];
    }

    private function roleOf(array $room, string $playerId): ?string
    {
        if ($room['host']['id'] === $playerId)            return 'host';
        if (($room['guest']['id'] ?? null) === $playerId) return 'guest';

        return null;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function error(string $message): array
    {
        return ['error' => $message];
    }
}
