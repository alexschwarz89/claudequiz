<?php

declare(strict_types=1);

namespace App\Room;

final class RoomRepository
{
    private const ROOMS_DIR   = __DIR__ . '/../../data/rooms';
    private const TTL_SECONDS = 7200;
    private const CODE_CHARS  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct()
    {
        if (!is_dir(self::ROOMS_DIR)) {
            mkdir(self::ROOMS_DIR, 0755, true);
        }
    }

    public function load(string $code): ?array
    {
        $path = $this->path($code);
        if (!is_file($path)) {
            return null;
        }

        $fp      = fopen($path, 'r');
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return json_decode($content, true);
    }

    public function save(array $room): void
    {
        $path = $this->path($room['code']);
        $fp   = fopen($path, 'c+');
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($room, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function generateCode(): string
    {
        $chars = self::CODE_CHARS;
        $len   = strlen($chars);

        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= $chars[random_int(0, $len - 1)];
            }
        } while (is_file($this->path($code)));

        return $code;
    }

    public function cleanup(): void
    {
        $cutoff = time() - self::TTL_SECONDS;
        foreach (glob(self::ROOMS_DIR . '/*.json') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    private function path(string $code): string
    {
        return self::ROOMS_DIR . '/' . strtoupper($code) . '.json';
    }
}
