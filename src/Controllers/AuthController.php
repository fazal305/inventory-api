<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Responses\ApiResponse;
use App\Services\AuthService;
use App\Support\AuthContext;

/**
 * Thin by design: decode nothing itself, apply no business rules itself —
 * just hand the request body to the Service and shape whatever comes back
 * into the response envelope.
 */
final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(array $params, ?array $body): void
    {
        $user = $this->auth->register($body ?? []);
        ApiResponse::success($user, status: 201);
    }

    public function login(array $params, ?array $body): void
    {
        $result = $this->auth->login($body ?? []);
        ApiResponse::success($result);
    }

    public function logout(array $params, ?array $body): void
    {
        $this->auth->logout(AuthContext::tokenId());
        ApiResponse::noContent();
    }
}
