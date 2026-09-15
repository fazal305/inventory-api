<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Support\ApiException;
use App\Validation\ProductQueryValidator;
use App\Validation\ProductValidator;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * @return array{data: array, meta: array{page: int, limit: int, total: int, totalPages: int}}
     */
    public function list(array $queryParams): array
    {
        $validated = ProductQueryValidator::validate($queryParams);

        $categoryId = null;
        if ($validated['category_slug'] !== null) {
            $category = $this->categories->findBySlug($validated['category_slug']);
            if ($category === null) {
                // A filter is a predicate, not a resource lookup: an unknown
                // slug means "nothing matches" (empty results), not a 404 —
                // unlike GET /categories/{id}, which names a specific resource
                // that either exists or doesn't.
                return $this->emptyPage($validated);
            }
            $categoryId = (int) $category['id'];
        }

        $result = $this->products->search([
            'search' => $validated['search'],
            'category_id' => $categoryId,
            'sort_column' => $validated['sort_column'],
            'sort_direction' => $validated['sort_direction'],
            'page' => $validated['page'],
            'limit' => $validated['limit'],
        ]);

        return [
            'data' => $result['rows'],
            'meta' => [
                'page' => $validated['page'],
                'limit' => $validated['limit'],
                'total' => $result['total'],
                'totalPages' => (int) ceil($result['total'] / $validated['limit']),
            ],
        ];
    }

    private function emptyPage(array $validated): array
    {
        return [
            'data' => [],
            'meta' => ['page' => $validated['page'], 'limit' => $validated['limit'], 'total' => 0, 'totalPages' => 0],
        ];
    }

    public function find(int $id): array
    {
        return $this->findOrFail($id);
    }

    public function create(array $data): array
    {
        $validated = ProductValidator::validateFull($data);
        $this->assertCategoryExists($validated['category_id']);
        $this->assertSkuAvailable($validated['sku']);

        return $this->products->create($validated);
    }

    public function replace(int $id, array $data): array
    {
        $this->findOrFail($id);
        $validated = ProductValidator::validateFull($data);
        $this->assertCategoryExists($validated['category_id']);
        $this->assertSkuAvailable($validated['sku'], excludeId: $id);

        return $this->products->update($id, $validated);
    }

    public function patch(int $id, array $data): array
    {
        $this->findOrFail($id);
        $validated = ProductValidator::validatePartial($data);

        if (array_key_exists('category_id', $validated)) {
            $this->assertCategoryExists($validated['category_id']);
        }
        if (array_key_exists('sku', $validated)) {
            $this->assertSkuAvailable($validated['sku'], excludeId: $id);
        }

        return $this->products->update($id, $validated);
    }

    public function delete(int $id): void
    {
        $this->findOrFail($id);
        $this->products->delete($id);
    }

    /**
     * Checked explicitly here (rather than relying only on the foreign key)
     * so the client gets a clear 422 with a field name instead of a raw
     * database failure translated after the fact — the FK is still the real
     * backstop, see ProductRepository::translate().
     */
    private function assertCategoryExists(int $categoryId): void
    {
        if ($this->categories->find($categoryId) === null) {
            throw new ApiException(
                'INVALID_CATEGORY',
                'category_id does not reference an existing category.',
                422,
                ['category_id' => 'No category with this id exists.']
            );
        }
    }

    private function assertSkuAvailable(string $sku, ?int $excludeId = null): void
    {
        $existing = $this->products->findBySku($sku);
        if ($existing !== null && (int) $existing['id'] !== $excludeId) {
            throw new ApiException('DUPLICATE_SKU', 'A product with this SKU already exists.', 409);
        }
    }

    private function findOrFail(int $id): array
    {
        $product = $this->products->find($id);
        if ($product === null) {
            throw new ApiException('NOT_FOUND', 'Product not found.', 404);
        }
        return $product;
    }
}
