<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class TokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO personal_access_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Returns the token row only if it is present, unexpired, and not revoked —
     * the three conditions that together mean "this token still authenticates".
     */
    public function findValid(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, expires_at
             FROM personal_access_tokens
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $token = $stmt->fetch();
        return $token === false ? null : $token;
    }

    public function revoke(int $tokenId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE personal_access_tokens SET revoked_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $tokenId]);
    }
}
