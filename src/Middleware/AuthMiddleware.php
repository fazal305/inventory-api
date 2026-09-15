<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\TokenRepository;
use App\Support\ApiException;
use App\Support\AuthContext;

/**
 * The reusable "is this caller who they claim to be" check (rule 23),
 * kept as one small class rather than a middleware pipeline framework —
 * this project has exactly one kind of protected route (authenticated or
 * not), so one class covers it without inventing unused generality.
 */
final class AuthMiddleware
{
    public function __construct(private readonly TokenRepository $tokens)
    {
    }

    public function handle(): void
    {
        $header = $this->authorizationHeader();

        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            throw new ApiException('UNAUTHORIZED', 'Authentication required.', 401);
        }

        $rawToken = substr($header, strlen('Bearer '));
        $tokenHash = hash('sha256', $rawToken);

        $token = $this->tokens->findValid($tokenHash);
        if ($token === null) {
            throw new ApiException('UNAUTHORIZED', 'Invalid or expired token.', 401);
        }

        AuthContext::set((int) $token['user_id'], (int) $token['id']);
    }

    private function authorizationHeader(): ?string
    {
        // Some SAPIs (older Apache setups) don't populate HTTP_AUTHORIZATION
        // in $_SERVER; getallheaders() is the portable fallback. PHP's
        // built-in dev server populates $_SERVER directly.
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($header === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        return $header;
    }
}
