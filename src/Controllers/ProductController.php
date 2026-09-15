<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Responses\ApiResponse;
use App\Services\ProductService;
use App\Support\RouteParams;

final class ProductController
{
    public function __construct(private readonly ProductService $products)
    {
    }

    public function index(array $params, ?array $body, array $query = []): void
    {
        $result = $this->products->list($query);
        ApiResponse::success($result['data'], $result['meta']);
    }

    public function show(array $params, ?array $body): void
    {
        ApiResponse::success($this->products->find($this->id($params)));
    }

    public function store(array $params, ?array $body): void
    {
        ApiResponse::success($this->products->create($body ?? []), status: 201);
    }

    public function replace(array $params, ?array $body): void
    {
        ApiResponse::success($this->products->replace($this->id($params), $body ?? []));
    }

    public function patch(array $params, ?array $body): void
    {
        ApiResponse::success($this->products->patch($this->id($params), $body ?? []));
    }

    public function destroy(array $params, ?array $body): void
    {
        $this->products->delete($this->id($params));
        ApiResponse::noContent();
    }

    private function id(array $params): int
    {
        return RouteParams::id($params);
    }
}
