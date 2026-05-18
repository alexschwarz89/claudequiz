<?php

declare(strict_types=1);

namespace App\Config;

final class EnvLoader
{
    public static function load(string $file): void
    {
        if (!file_exists($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim(trim($value), '"\'');

            if ($key === '') {
                continue;
            }

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
