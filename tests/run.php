<?php

declare(strict_types=1);

/**
 * Integration test suite for the running API — no PHPUnit.
 *
 * Composer isn't available in this environment (see the Phase 2 note in
 * README/CONCEPTS), and installing PHPUnit without it would mean downloading
 * an executable .phar from the internet, which needs explicit sign-off
 * rather than being a call this script makes for itself. Everything PHPUnit
 * would give here — repeatable, scriptable, pass/fail assertions instead of
 * re-running curl by hand — is achievable with plain PHP hitting the live
 * server over HTTP, which is what this does. It's an integration suite
 * (exercises the real router → controller → service → repository → MySQL
 * chain end to end), not a unit suite with mocked dependencies — for a
 * project this size, the integration is the part actually worth automating.
 *
 * Prerequisite: the dev server must be running
 *   php -S 127.0.0.1:8000 -t public public/index.php
 * and pointed at a database that has run database/migrate.php +
 * database/seeders/seed.php (the assertions below assume the seed data:
 * 4 categories, "Electronics" with 8 products, SKU-1001 already taken).
 *
 * Usage: php tests/run.php
 */

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8000/api/v1';
$runId = (string) time();

$passed = 0;
$failed = 0;
$failures = [];

function request(string $baseUrl, string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body !== null ? json_encode($body) : '',
            'ignore_errors' => true, // so 4xx/5xx responses are returned, not thrown as warnings
        ],
    ]);

    $raw = @file_get_contents($baseUrl . $path, false, $context);

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\d\.\d (\d+)#', $header, $m)) {
            $status = (int) $m[1];
        }
    }

    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;

    return ['status' => $status, 'body' => $decoded];
}

function check(bool $condition, string $description): void
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  PASS  {$description}\n";
    } else {
        $failed++;
        $failures[] = $description;
        echo "  FAIL  {$description}\n";
    }
}

function status(array $response): int
{
    return $response['status'];
}

function code(array $response): ?string
{
    return $response['body']['error']['code'] ?? null;
}

// ============================================================ Health

echo "-- Health --\n";
$r = request($baseUrl, 'GET', '/health');
check(status($r) === 200, 'GET /health -> 200');

// ============================================================ Auth

echo "-- Auth --\n";
$email = "test+{$runId}@example.com";

$r = request($baseUrl, 'POST', '/auth/register', ['name' => 'Test User', 'email' => $email, 'password' => 'secret123']);
check(status($r) === 201, 'register valid -> 201');
check(!isset($r['body']['data']['password_hash']), 'register response never includes password_hash');

$r = request($baseUrl, 'POST', '/auth/register', ['name' => 'Test User', 'email' => $email, 'password' => 'secret123']);
check(status($r) === 409 && code($r) === 'DUPLICATE_EMAIL', 'register duplicate email -> 409 DUPLICATE_EMAIL');

$r = request($baseUrl, 'POST', '/auth/register', ['name' => '', 'email' => 'not-an-email', 'password' => 'short']);
check(status($r) === 422 && count($r['body']['error']['details']) === 3, 'register invalid -> 422 with 3 field errors');

$r = request($baseUrl, 'POST', '/auth/login', ['email' => $email, 'password' => 'wrong-password']);
check(status($r) === 401 && code($r) === 'INVALID_CREDENTIALS', 'login wrong password -> 401');

$r = request($baseUrl, 'POST', '/auth/login', ['email' => $email, 'password' => 'secret123']);
check(status($r) === 200 && !empty($r['body']['data']['token']), 'login correct -> 200 with token');
$disposableToken = $r['body']['data']['token'];

$r = request($baseUrl, 'POST', '/auth/logout');
check(status($r) === 401, 'logout without token -> 401');

$r = request($baseUrl, 'POST', '/auth/logout', null, $disposableToken);
check(status($r) === 204, 'logout with valid token -> 204');

$r = request($baseUrl, 'POST', '/auth/logout', null, $disposableToken);
check(status($r) === 401, 'logout with already-revoked token -> 401');

// A fresh, still-valid token for every write test below.
$r = request($baseUrl, 'POST', '/auth/login', ['email' => $email, 'password' => 'secret123']);
$token = $r['body']['data']['token'];

// ============================================================ Categories

echo "-- Categories --\n";

$r = request($baseUrl, 'GET', '/categories');
check(status($r) === 200 && count($r['body']['data']) >= 4, 'list categories -> 200, seeded rows present');

$r = request($baseUrl, 'GET', '/categories/1');
check(status($r) === 200, 'get category 1 -> 200');

$r = request($baseUrl, 'GET', '/categories/999999');
check(status($r) === 404, 'get nonexistent category -> 404');

$r = request($baseUrl, 'GET', '/categories/abc');
check(status($r) === 400 && code($r) === 'INVALID_ID', 'get category with non-numeric id -> 400');

$categoryName = "Test Category {$runId}";

$r = request($baseUrl, 'POST', '/categories', ['name' => $categoryName]);
check(status($r) === 401, 'create category without token -> 401');

$r = request($baseUrl, 'POST', '/categories', ['name' => $categoryName, 'description' => 'temp'], $token);
check(status($r) === 201 && $r['body']['data']['slug'] !== '', 'create category -> 201 with slug');
$categoryId = $r['body']['data']['id'];

$r = request($baseUrl, 'POST', '/categories', ['name' => $categoryName], $token);
check(status($r) === 409 && code($r) === 'DUPLICATE_CATEGORY', 'create duplicate category name -> 409');

$r = request($baseUrl, 'POST', '/categories', ['name' => ''], $token);
check(status($r) === 422, 'create category blank name -> 422');

$r = request($baseUrl, 'PUT', "/categories/{$categoryId}", ['name' => "{$categoryName} Renamed", 'description' => 'renamed'], $token);
check(status($r) === 200 && $r['body']['data']['name'] === "{$categoryName} Renamed", 'PUT category rename -> 200');

$r = request($baseUrl, 'PATCH', "/categories/{$categoryId}", ['description' => 'patched'], $token);
check(status($r) === 200 && $r['body']['data']['description'] === 'patched', 'PATCH category description only -> 200');

$r = request($baseUrl, 'PATCH', "/categories/{$categoryId}", [], $token);
check(status($r) === 422, 'PATCH category empty body -> 422');

$r = request($baseUrl, 'DELETE', '/categories/1', null, $token);
check(status($r) === 409 && code($r) === 'CATEGORY_HAS_PRODUCTS', 'delete category with products -> 409');

$r = request($baseUrl, 'DELETE', "/categories/{$categoryId}", null, $token);
check(status($r) === 204, 'delete empty test category -> 204');

$r = request($baseUrl, 'DELETE', "/categories/{$categoryId}", null, $token);
check(status($r) === 404, 'delete already-deleted category -> 404');

// ============================================================ Products

echo "-- Products --\n";

$r = request($baseUrl, 'GET', '/products');
check(status($r) === 200, 'list products -> 200');

$r = request($baseUrl, 'GET', '/products/1');
check(status($r) === 200, 'get product 1 -> 200');

$r = request($baseUrl, 'GET', '/products/999999');
check(status($r) === 404, 'get nonexistent product -> 404');

$sku = "SKU-TEST-{$runId}";

$r = request($baseUrl, 'POST', '/products', ['name' => 'Test Product', 'sku' => $sku, 'category_id' => 1, 'price' => 19.99, 'quantity' => 5]);
check(status($r) === 401, 'create product without token -> 401');

$r = request($baseUrl, 'POST', '/products', ['name' => 'Test Product', 'sku' => $sku, 'category_id' => 1, 'price' => 19.99, 'quantity' => 5], $token);
check(status($r) === 201, 'create product -> 201');
$productId = $r['body']['data']['id'];

$r = request($baseUrl, 'POST', '/products', ['name' => 'Dup SKU', 'sku' => $sku, 'category_id' => 1, 'price' => 9.99, 'quantity' => 1], $token);
check(status($r) === 409 && code($r) === 'DUPLICATE_SKU', 'create product duplicate sku -> 409');

$r = request($baseUrl, 'POST', '/products', ['name' => 'Bad Cat', 'sku' => "{$sku}-B", 'category_id' => 999999, 'price' => 9.99, 'quantity' => 1], $token);
check(status($r) === 422 && code($r) === 'INVALID_CATEGORY', 'create product invalid category_id -> 422');

$r = request($baseUrl, 'POST', '/products', ['name' => 'Neg Price', 'sku' => "{$sku}-C", 'category_id' => 1, 'price' => -5, 'quantity' => 1], $token);
check(status($r) === 422, 'create product negative price -> 422');

$r = request($baseUrl, 'POST', '/products', ['name' => 'Neg Qty', 'sku' => "{$sku}-D", 'category_id' => 1, 'price' => 5, 'quantity' => -1], $token);
check(status($r) === 422, 'create product negative quantity -> 422');

$r = request($baseUrl, 'PUT', "/products/{$productId}", ['name' => 'Test Product v2', 'sku' => $sku, 'category_id' => 2, 'price' => 24.99, 'quantity' => 10], $token);
check(status($r) === 200 && (int) $r['body']['data']['category_id'] === 2, 'PUT product full replace -> 200');

$r = request($baseUrl, 'PATCH', "/products/{$productId}", ['quantity' => 50], $token);
check(status($r) === 200 && (int) $r['body']['data']['quantity'] === 50, 'PATCH product quantity only -> 200');

$r = request($baseUrl, 'PATCH', "/products/{$productId}", ['sku' => 'SKU-1001'], $token);
check(status($r) === 409 && code($r) === 'DUPLICATE_SKU', 'PATCH product sku collides with another product -> 409');

$r = request($baseUrl, 'PATCH', "/products/{$productId}", [], $token);
check(status($r) === 422, 'PATCH product empty body -> 422');

$r = request($baseUrl, 'DELETE', "/products/{$productId}", null, $token);
check(status($r) === 204, 'delete product -> 204');

$r = request($baseUrl, 'DELETE', "/products/{$productId}", null, $token);
check(status($r) === 404, 'delete already-deleted product -> 404');

// ============================================================ Query features

echo "-- Query features --\n";

$r = request($baseUrl, 'GET', '/products?limit=5');
check(status($r) === 200 && count($r['body']['data']) === 5 && $r['body']['meta']['limit'] === 5, 'pagination limit=5 -> 5 rows');

$r = request($baseUrl, 'GET', '/products?search=Keyboard');
check(status($r) === 200 && count($r['body']['data']) === 1, 'search=Keyboard -> 1 result');

$r = request($baseUrl, 'GET', '/products?category=electronics&limit=100');
check(status($r) === 200 && $r['body']['meta']['total'] === 8, 'category=electronics -> total 8');

$r = request($baseUrl, 'GET', '/products?category=does-not-exist');
check(status($r) === 200 && $r['body']['meta']['total'] === 0, 'category=does-not-exist -> empty, not 404');

$r = request($baseUrl, 'GET', '/products?sort=bogus');
check(status($r) === 422, 'sort=bogus -> 422');

$r = request($baseUrl, 'GET', '/products?page=abc');
check(status($r) === 422, 'page=abc -> 422');

$r = request($baseUrl, 'GET', '/products?limit=0');
check(status($r) === 422, 'limit=0 -> 422');

$r = request($baseUrl, 'GET', '/products?limit=99999');
check(status($r) === 200 && $r['body']['meta']['limit'] === 100, 'limit=99999 -> capped to 100');

// ============================================================ Protocol-level

echo "-- Protocol-level --\n";

$r = request($baseUrl, 'POST', '/health');
check(status($r) === 405, 'unsupported method on known route -> 405');

$r = request($baseUrl, 'GET', '/nope');
check(status($r) === 404 && code($r) === 'ROUTE_NOT_FOUND', 'unknown route -> 404');

$context = stream_context_create([
    'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => '{bad json', 'ignore_errors' => true],
]);
@file_get_contents($baseUrl . '/auth/login', false, $context);
$malformedStatus = 0;
foreach ($http_response_header ?? [] as $header) {
    if (preg_match('#^HTTP/\d\.\d (\d+)#', $header, $m)) {
        $malformedStatus = (int) $m[1];
    }
}
check($malformedStatus === 400, 'malformed JSON body -> 400');

// ============================================================ Summary

echo "\n" . str_repeat('-', 40) . "\n";
echo "{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    echo "\nFailed:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

exit(0);
