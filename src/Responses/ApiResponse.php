<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * The single place that shapes every JSON body this API sends, so success
 * and error payloads stay in the same envelope no matter which controller
 * produced them.
 */
final class ApiResponse
{
    public static function success(mixed $data, ?array $meta = null, int $status = 200): never
    {
        self::send($status, [
            'success' => true,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    /**
     * A 204 must not carry a response body (RFC 7231) — a bare success
     * envelope would violate that, so this sends headers only.
     */
    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function error(string $code, string $message, ?array $details = null, int $status = 400): never
    {
        self::send($status, [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ]);
    }

    private static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
