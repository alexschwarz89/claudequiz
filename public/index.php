<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\EnvLoader;
use App\Repository\QuestionRepository;
use App\Repository\ReportRepository;
use App\Repository\ScoreRepository;
use App\Room\RoomRepository;
use App\Room\RoomService;

EnvLoader::load(__DIR__ . '/../.env');

if (PHP_SAPI === 'cli-server') {
    $filePath = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($filePath)) {
        return false;
    }
}

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return;
}

if ($path === '/api/questions' && $method === 'GET') {
    jsonResponse((new QuestionRepository(__DIR__ . '/../data/questions.json'))->findAllRaw());
    return;
}

if (str_starts_with($path, '/api/room')) {
    handleRoomRoute($path, $method);
    return;
}

if ($path === '/api/report' && $method === 'POST') {
    $id = trim(jsonBody()['id'] ?? '');
    if ($id === '') { jsonResponse(['error' => 'Missing id'], 400); return; }
    (new ReportRepository(__DIR__ . '/../data/reported_questions.json'))->add($id);
    jsonResponse(['ok' => true]);
    return;
}

if ($path === '/api/scores' && $method === 'GET') {
    jsonResponse(['scores' => (new ScoreRepository())->load()]);
    return;
}

if ($path === '/api/scores' && $method === 'POST') {
    handleScorePost(jsonBody());
    return;
}

jsonResponse(['error' => 'Not found'], 404);

// ---- helpers ----

function handleRoomRoute(string $path, string $method): void
{
    $service  = new RoomService(
        new RoomRepository(),
        new QuestionRepository(__DIR__ . '/../data/questions.json'),
    );
    $body     = jsonBody();
    $segments = explode('/', trim($path, '/'));
    $code     = $segments[2] ?? null;
    $action   = $segments[3] ?? null;

    if ($code === null && $method === 'POST') {
        jsonResponse($service->create(
            $body['playerName'] ?? 'Host',
            $body['categories'] ?? [],
            (int) ($body['count'] ?? 10),
        ));
        return;
    }

    if ($code === 'join' && $method === 'POST') {
        $result = $service->join(strtoupper($body['code'] ?? ''), $body['playerName'] ?? 'Gast');
        jsonResponse($result, isset($result['error']) ? 400 : 200);
        return;
    }

    if ($code && $action === null && $method === 'GET') {
        $room = $service->poll(strtoupper($code));
        $room === null ? jsonResponse(['error' => 'Not found'], 404) : jsonResponse($room);
        return;
    }

    if ($code && $action === 'start' && $method === 'POST') {
        $result = $service->start(strtoupper($code), $body['playerId'] ?? '');
        jsonResponse($result, isset($result['error']) ? 400 : 200);
        return;
    }

    if ($code && $action === 'answer' && $method === 'POST') {
        $result = $service->answer(strtoupper($code), $body['playerId'] ?? '', (bool) ($body['correct'] ?? false), (int) ($body['solved'] ?? 0));
        jsonResponse($result, isset($result['error']) ? 400 : 200);
        return;
    }

    if ($code && $action === 'restart' && $method === 'POST') {
        $result = $service->restart(strtoupper($code), $body['playerId'] ?? '');
        jsonResponse($result, isset($result['error']) ? 400 : 200);
        return;
    }

    jsonResponse(['error' => 'Not found'], 404);
}

function handleScorePost(array $body): void
{
    $name  = substr(trim($body['name'] ?? ''), 0, 30);
    $score = (int) ($body['score'] ?? -1);
    $total = (int) ($body['total'] ?? 0);

    if ($name === '' || $score < 0 || $total <= 0) {
        jsonResponse(['error' => 'Invalid data'], 400);
        return;
    }

    $repo = new ScoreRepository();
    if (!$repo->qualifies($score)) {
        jsonResponse(['error' => 'Score does not qualify'], 400);
        return;
    }

    $repo->save($name, $score, $total);
    jsonResponse(['scores' => $repo->load()]);
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function jsonBody(): array
{
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
