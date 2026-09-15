<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CategoryRepository;
use App\Support\ApiException;
use App\Support\Slug;
use App\Validation\CategoryValidator;

final class CategoryService
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    public function list(): array
    {
        return $this->categories->all();
    }

    public function find(int $id): array
    {
        return $this->findOrFail($id);
    }

    public function create(array $data): array
    {
        $validated = CategoryValidator::validateFull($data);

        if ($this->categories->findByName($validated['name']) !== null) {
            throw new ApiException('DUPLICATE_CATEGORY', 'A category with this name already exists.', 409);
        }

        return $this->categories->create($validated['name'], Slug::make($validated['name']), $validated['description']);
    }

    public function replace(int $id, array $data): array
    {
        $this->findOrFail($id);
        $validated = CategoryValidator::validateFull($data);
        return $this->applyUpdate($id, $validated);
    }

    public function patch(int $id, array $data): array
    {
        $this->findOrFail($id);
        $validated = CategoryValidator::validatePartial($data);
        return $this->applyUpdate($id, $validated);
    }

    public function delete(int $id): void
    {
        $this->findOrFail($id);

        $productCount = $this->categories->productCount($id);
        if ($productCount > 0) {
            throw new ApiException(
                'CATEGORY_HAS_PRODUCTS',
                'This category has products assigned to it and cannot be deleted.',
                409,
                ['product_count' => $productCount]
            );
        }

        $this->categories->delete($id);
    }

    private function applyUpdate(int $id, array $fields): array
    {
        // Renaming a category means its slug (what product filtering uses)
        // must change with it, or /products?category=old-slug would silently
        // keep working while the category is now called something else.
        if (array_key_exists('name', $fields)) {
            $fields['slug'] = Slug::make($fields['name']);
        }

        return $this->categories->update($id, $fields);
    }

    private function findOrFail(int $id): array
    {
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new ApiException('NOT_FOUND', 'Category not found.', 404);
        }
        return $category;
    }
}
