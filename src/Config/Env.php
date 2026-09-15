<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Minimal .env loader: reads KEY=VALUE lines into getenv()/$_ENV so the rest
 * of the app never touches the file directly. No external dependency —
 * the parsing needed for this project's flat, quote-free .env is a dozen lines.
 *
 * A missing file is not an error: locally, a real .env file is the
 * convenient way to set config (copy .env.example, edit, done). On a host
 * like Render, there is no .env file at all — configuration is injected
 * directly into the process environment via the platform's dashboard, and
 * getenv() already sees it without this class doing anything. Throwing
 * here would break every such deployment for no reason.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path)) {
            self::$loaded = true;
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
