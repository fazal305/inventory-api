<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ApiException;
use PDO;
use PDOException;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $name, string $slug, ?string $description): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (name, slug, description) VALUES (:name, :slug, :description)'
        );

        try {
            $stmt->execute(['name' => $name, 'slug' => $slug, 'description' => $description]);
        } catch (PDOException $e) {
            // 23000 = MySQL's generic integrity-constraint-violation SQLSTATE;
            // 23505 = Postgres's specific unique-violation SQLSTATE. Either
            // way this is a unique constraint on name or slug. The app
            // already checked name uniqueness before this call — this catch
            // is the real backstop against the race between that check and
            // this insert, and against a slug collision from two
            // differently-named categories (e.g. "Home & Garden" / "Home And
            // Garden" both slugifying to "home-garden").
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                throw new ApiException('DUPLICATE_CATEGORY', 'A category with this name already exists.', 409);
            }
            throw $e;
        }

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $fields): array
    {
        // $fields keys come only from CategoryValidator's allowlisted output,
        // never directly from client-supplied keys — safe to use as column
        // names here because they were never attacker-controlled to begin with.
        $set = implode(', ', array_map(static fn ($col) => "{$col} = :{$col}", array_keys($fields)));
        $stmt = $this->pdo->prepare("UPDATE categories SET {$set} WHERE id = :id");

        try {
            $stmt->execute([...$fields, 'id' => $id]);
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                throw new ApiException('DUPLICATE_CATEGORY', 'A category with this name already exists.', 409);
            }
            throw $e;
        }

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function productCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }
}
