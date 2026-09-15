<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Responses\ApiResponse;
use App\Services\CategoryService;
use App\Support\RouteParams;

final class CategoryController
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    public function index(array $params, ?array $body): void
    {
        ApiResponse::success($this->categories->list());
    }

    public function show(array $params, ?array $body): void
    {
        ApiResponse::success($this->categories->find($this->id($params)));
    }

    public function store(array $params, ?array $body): void
    {
        ApiResponse::success($this->categories->create($body ?? []), status: 201);
    }

    public function replace(array $params, ?array $body): void
    {
        ApiResponse::success($this->categories->replace($this->id($params), $body ?? []));
    }

    public function patch(array $params, ?array $body): void
    {
        ApiResponse::success($this->categories->patch($this->id($params), $body ?? []));
    }

    public function destroy(array $params, ?array $body): void
    {
        $this->categories->delete($this->id($params));
        ApiResponse::noContent();
    }

    private function id(array $params): int
    {
        return RouteParams::id($params);
    }
}
