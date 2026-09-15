<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Support\ApiException;
use App\Validation\AuthValidator;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly int $tokenTtlSeconds,
    ) {
    }

    /**
     * @return array the created user (never includes password_hash)
     */
    public function register(array $data): array
    {
        $validated = AuthValidator::validateRegister($data);

        if ($this->users->findByEmail($validated['email']) !== null) {
            // 409, not 422: the request is well-formed, it just conflicts with
            // state that already exists (rule 16).
            throw new ApiException('DUPLICATE_EMAIL', 'An account with this email already exists.', 409);
        }

        $passwordHash = password_hash($validated['password'], PASSWORD_DEFAULT);
        $user = $this->users->create($validated['name'], $validated['email'], $passwordHash);

        unset($user['password_hash']);
        return $user;
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    public function login(array $data): array
    {
        $validated = AuthValidator::validateLogin($data);

        $user = $this->users->findByEmail($validated['email']);

        // Same error for "no such user" and "wrong password" — telling an
        // attacker which one is true would let them enumerate valid emails.
        if ($user === null || !password_verify($validated['password'], $user['password_hash'])) {
            throw new ApiException('INVALID_CREDENTIALS', 'Email or password is incorrect.', 401);
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = new \DateTimeImmutable("+{$this->tokenTtlSeconds} seconds");

        $this->tokens->create((int) $user['id'], $tokenHash, $expiresAt);

        return ['token' => $rawToken, 'expires_at' => $expiresAt->format(DATE_ATOM)];
    }

    public function logout(int $tokenId): void
    {
        $this->tokens->revoke($tokenId);
    }
}
