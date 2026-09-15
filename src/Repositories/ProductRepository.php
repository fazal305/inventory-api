<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ApiException;
use PDO;
use PDOException;

final class ProductRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{search: ?string, category_id: ?int, sort_column: string, sort_direction: string, page: int, limit: int} $criteria
     * @return array{rows: array, total: int}
     */
    public function search(array $criteria): array
    {
        $where = [];
        $params = [];

        if ($criteria['search'] !== null) {
            $where[] = 'name LIKE :search';
            $params['search'] = '%' . $criteria['search'] . '%';
        }

        if ($criteria['category_id'] !== null) {
            $where[] = 'category_id = :category_id';
            $params['category_id'] = $criteria['category_id'];
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($criteria['page'] - 1) * $criteria['limit'];

        // sort_column/sort_direction come only from ProductQueryValidator's fixed
        // allowlist, never the raw query string — see the note on identifiers
        // vs values in that validator.
        $sql = "SELECT * FROM products {$whereSql}
                ORDER BY {$criteria['sort_column']} {$criteria['sort_direction']}
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        // LIMIT/OFFSET must be bound as real integers, not the default PDO
        // string type — with emulated prepares off (Database.php), MySQL's
        // native protocol rejects a string-typed LIMIT value outright.
        $stmt->bindValue(':limit', $criteria['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findBySku(string $sku): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = :sku');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(array $fields): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (category_id, name, sku, description, price, quantity)
             VALUES (:category_id, :name, :sku, :description, :price, :quantity)'
        );

        try {
            $stmt->execute($fields);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $fields): array
    {
        // Keys come only from ProductValidator's fixed allowlist, never
        // directly from client-supplied JSON keys — see the same note in
        // CategoryRepository::update.
        $set = implode(', ', array_map(static fn ($col) => "{$col} = :{$col}", array_keys($fields)));
        $stmt = $this->pdo->prepare("UPDATE products SET {$set} WHERE id = :id");

        try {
            $stmt->execute([...$fields, 'id' => $id]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * MySQL reports both a duplicate unique key and a failed foreign key as
     * SQLSTATE 23000 — the driver-specific error code (1062 vs 1452) is what
     * actually distinguishes them, so the backstop here has to check that,
     * not just the SQLSTATE, to return the right error code to the client.
     */
    private function translate(PDOException $e): ApiException
    {
        $driverCode = $e->errorInfo[1] ?? null;

        if ($driverCode === 1062) {
            return new ApiException('DUPLICATE_SKU', 'A product with this SKU already exists.', 409);
        }

        if ($driverCode === 1452) {
            return new ApiException('INVALID_CATEGORY', 'category_id does not reference an existing category.', 422);
        }

        return new ApiException('INTERNAL_SERVER_ERROR', 'Something went wrong.', 500);
    }
}
