<?php
declare(strict_types=1);

namespace QRoute\Http;

/**
 * Tiny regex router. Patterns use {name} for a path segment and
 * {name:regex} for a constrained segment.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:callable}> */
    private array $routes = [];

    /** @var callable|null */
    private $notFound = null;

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** Registers the same handler for GET and POST. */
    public function any(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    public function fallback(callable $handler): void
    {
        $this->notFound = $handler;
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = '';
        $offset = 0;

        // Literal segments are quoted so that a dot in a pattern such as
        // "/qr/{slug}.{format}" matches a dot and not any character.
        preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/',
            $pattern,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $start = (int) $m[0][1];
            $regex .= preg_quote(substr($pattern, $offset, $start - $offset), '#');
            $params[] = $m[1][0];
            $regex .= '(' . ($m[2][0] ?? '[^/]+') . ')';
            $offset = $start + strlen($m[0][0]);
        }
        $regex .= preg_quote(substr($pattern, $offset), '#');

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        // A HEAD request is served by the GET handler; the response layer
        // drops the body. Refusing HEAD breaks link checkers and monitors.
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;

        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = $m[$i + 1] ?? '';
            }
            return ($route['handler'])($request, $args);
        }

        if ($pathMatched) {
            return Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET, POST');
        }
        if ($this->notFound !== null) {
            return ($this->notFound)($request);
        }
        return Response::text('Not Found', 404);
    }
}
