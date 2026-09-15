<?php

declare(strict_types=1);

namespace App\Support;

final class RouteParams
{
    public static function id(array $params, string $key = 'id'): int
    {
        if (!isset($params[$key]) || !ctype_digit((string) $params[$key])) {
            throw new ApiException('INVALID_ID', 'The resource id must be a positive integer.', 400);
        }
        return (int) $params[$key];
    }
}
