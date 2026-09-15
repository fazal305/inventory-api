<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Small regex-based router. A route like "/products/{id}" is compiled once
 * into a pattern with a named capture group, so dispatch() can turn a real
 * request path into a params array without any framework dependency.
 *
 * Deliberately flat: no route groups, no auto-generated URLs. This project
 * has ~12 routes total — that ceremony would cost more to read than it saves.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, paramNames: list<string>, handler: callable, middleware: list<callable>}> */
    private array $routes = [];

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $path);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * @return array{status: 'matched', handler: callable, params: array<string,string>, middleware: list<callable>}
     *       | array{status: 'method_not_allowed', allowed: list<string>}
     *       | array{status: 'not_found'}
     */
    public function dispatch(string $method, string $path): array
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $params = array_filter(
                $matches,
                static fn ($key) => is_string($key),
                ARRAY_FILTER_USE_KEY
            );

            return [
                'status' => 'matched',
                'handler' => $route['handler'],
                'params' => $params,
                'middleware' => $route['middleware'],
            ];
        }

        if ($allowedMethods !== []) {
            return ['status' => 'method_not_allowed', 'allowed' => array_unique($allowedMethods)];
        }

        return ['status' => 'not_found'];
    }
}
