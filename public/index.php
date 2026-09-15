<?php

declare(strict_types=1);

use App\Config\Database;
use App\Config\Env;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\ProductController;
use App\Middleware\AuthMiddleware;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Responses\ApiResponse;
use App\Routing\Router;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\ProductService;
use App\Support\ApiException;

// --- Autoloading -----------------------------------------------------------
// No Composer available in this environment, so PSR-4-style autoloading is
// done by hand: "App\Foo\Bar" -> src/Foo/Bar.php. Same mapping Composer's
// generated autoloader would produce for {"App\\": "src/"}; if Composer
// becomes available later this can be swapped for vendor/autoload.php with
// no change to any class.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- Error handling ----------------------------------------------------
// Anything that reaches here is a bug or an infrastructure failure, not a
// handled application error (those are returned directly by controllers via
// ApiResponse). The client always gets a safe, generic JSON body; real
// detail goes to the PHP error log, never into the response.
set_exception_handler(function (\Throwable $e): void {
    if ($e instanceof ApiException) {
        ApiResponse::error($e->errorCode(), $e->getMessage(), $e->details(), $e->statusCode());
    }

    error_log(sprintf('[UNHANDLED] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    ApiResponse::error('INTERNAL_SERVER_ERROR', 'Something went wrong.', status: 500);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

Env::load(__DIR__ . '/../.env');

// --- CORS ------------------------------------------------------------------
// A browser tab on a different origin (the future React app on e.g.
// localhost:3000) is blocked by its OWN browser from reading this API's
// responses unless the API explicitly opts that origin in. This never
// uses "Access-Control-Allow-Origin: *": with `*`, ANY website the user has
// open could script a request to this API from the visitor's browser and
// read the response. Reflecting back only an origin found in the .env
// allowlist gets the same "React app can call this" outcome without opening
// the API to every other site on the internet. `Vary: Origin` tells any
// caching layer the response differs per origin, so a cache can't serve one
// origin's CORS headers to another.
$allowedOrigins = array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS', ''))));
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;

if ($requestOrigin !== null && in_array($requestOrigin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$requestOrigin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
}

// --- Request parsing ---------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(strtok($_SERVER['REQUEST_URI'], '?'), '/');
if ($path === '') {
    $path = '/';
}

// A browser sends a preflight OPTIONS request before the real cross-origin
// call whenever it uses a non-"simple" method or header (PUT/PATCH/DELETE,
// or Authorization) — it's asking permission first. No route handles OPTIONS
// itself; the CORS headers above already answered that question, so this
// just closes the preflight out before it reaches routing at all.
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$body = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error('INVALID_JSON', 'Request body is not valid JSON.', status: 400);
        }
        if (!is_array($body)) {
            ApiResponse::error('INVALID_JSON', 'Request body must be a JSON object.', status: 400);
        }
    } else {
        $body = [];
    }
}

// --- Routes --------------------------------------------------------------
$router = new Router();

$router->get('/api/v1/health', function () {
    ApiResponse::success(['status' => 'ok', 'time' => date(DATE_ATOM)]);
});

// Lazy singletons: a request to /health never touches the database, and a
// closure-per-dependency is enough wiring for four routes — a full DI
// container would be solving a problem this project doesn't have.
$tokenRepository = function (): TokenRepository {
    static $instance = null;
    return $instance ??= new TokenRepository(Database::connection());
};

$authController = function () use ($tokenRepository): AuthController {
    static $instance = null;
    if ($instance === null) {
        $userRepository = new UserRepository(Database::connection());
        $ttl = (int) Env::get('TOKEN_TTL_SECONDS', '604800');
        $instance = new AuthController(new AuthService($userRepository, $tokenRepository(), $ttl));
    }
    return $instance;
};

$requireAuth = fn () => (new AuthMiddleware($tokenRepository()))->handle();

$router->post('/api/v1/auth/register', fn ($params, $body) => $authController()->register($params, $body));
$router->post('/api/v1/auth/login', fn ($params, $body) => $authController()->login($params, $body));
$router->post('/api/v1/auth/logout', fn ($params, $body) => $authController()->logout($params, $body), [$requireAuth]);

$categoryRepository = function (): CategoryRepository {
    static $instance = null;
    return $instance ??= new CategoryRepository(Database::connection());
};

$categoryController = function () use ($categoryRepository): CategoryController {
    static $instance = null;
    $instance ??= new CategoryController(new CategoryService($categoryRepository()));
    return $instance;
};

$router->get('/api/v1/categories', fn ($params, $body) => $categoryController()->index($params, $body));
$router->get('/api/v1/categories/{id}', fn ($params, $body) => $categoryController()->show($params, $body));
$router->post('/api/v1/categories', fn ($params, $body) => $categoryController()->store($params, $body), [$requireAuth]);
$router->put('/api/v1/categories/{id}', fn ($params, $body) => $categoryController()->replace($params, $body), [$requireAuth]);
$router->patch('/api/v1/categories/{id}', fn ($params, $body) => $categoryController()->patch($params, $body), [$requireAuth]);
$router->delete('/api/v1/categories/{id}', fn ($params, $body) => $categoryController()->destroy($params, $body), [$requireAuth]);

$productController = function () use ($categoryRepository): ProductController {
    static $instance = null;
    if ($instance === null) {
        $productRepository = new ProductRepository(Database::connection());
        $instance = new ProductController(new ProductService($productRepository, $categoryRepository()));
    }
    return $instance;
};

$router->get('/api/v1/products', fn ($params, $body) => $productController()->index($params, $body, $_GET));
$router->get('/api/v1/products/{id}', fn ($params, $body) => $productController()->show($params, $body));
$router->post('/api/v1/products', fn ($params, $body) => $productController()->store($params, $body), [$requireAuth]);
$router->put('/api/v1/products/{id}', fn ($params, $body) => $productController()->replace($params, $body), [$requireAuth]);
$router->patch('/api/v1/products/{id}', fn ($params, $body) => $productController()->patch($params, $body), [$requireAuth]);
$router->delete('/api/v1/products/{id}', fn ($params, $body) => $productController()->destroy($params, $body), [$requireAuth]);

// --- Dispatch --------------------------------------------------------------
$result = $router->dispatch($method, $path);

switch ($result['status']) {
    case 'matched':
        foreach ($result['middleware'] as $middleware) {
            $middleware();
        }
        ($result['handler'])($result['params'], $body);
        break;

    case 'method_not_allowed':
        header('Allow: ' . implode(', ', $result['allowed']));
        ApiResponse::error(
            'METHOD_NOT_ALLOWED',
            'This HTTP method is not supported for this endpoint.',
            ['allowed' => $result['allowed']],
            405
        );
        break;

    case 'not_found':
        ApiResponse::error('ROUTE_NOT_FOUND', 'The requested API endpoint does not exist.', status: 404);
        break;
}
