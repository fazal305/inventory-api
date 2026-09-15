<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Holds "who is making this request" for the lifetime of the request.
 * PHP tears down all static state between requests (each request is a
 * fresh process/execution under the built-in server, PHP-FPM, etc.), so a
 * static here is request-scoped in practice, not a global shared across
 * users — the same reasoning frameworks rely on for their "current user"
 * helpers. AuthMiddleware sets it; controllers read it.
 */
final class AuthContext
{
    private static ?array $user = null;

    public static function set(int $userId, int $tokenId): void
    {
        self::$user = ['user_id' => $userId, 'token_id' => $tokenId];
    }

    public static function userId(): ?int
    {
        return self::$user['user_id'] ?? null;
    }

    public static function tokenId(): ?int
    {
        return self::$user['token_id'] ?? null;
    }
}
