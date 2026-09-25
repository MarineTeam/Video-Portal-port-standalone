<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routes paths written the way the original's file tree wrote them —
 * /api/admin/files/[id]/contents — to handlers.
 *
 * A literal segment always beats a parameter in the same position, so
 * /api/admin/files/bulk is never read as a file whose id is "bulk", whatever
 * order the two were registered in. A path that matches with the wrong method
 * answers 405 with an Allow header rather than 404.
 */
final class Router
{
    /** @var array<string, array{pattern: string, regex: string, params: list<string>, score: string, methods: array<string, array{handler: callable, middleware: list<callable>}>}> */
    private array $routes = [];

    private bool $sorted = true;

    /** @var list<callable> */
    private array $groupMiddleware = [];

    /**
     * @param string|list<string> $methods
     * @param callable(Request, array<string, string>): Response $handler
     * @param list<callable(Request, callable): Response> $middleware
     */
    public function add(string|array $methods, string $pattern, callable $handler, array $middleware = []): self
    {
        $pattern = $pattern === '/' ? '/' : '/' . trim($pattern, '/');
        if (!isset($this->routes[$pattern])) {
            [$regex, $params, $score] = self::compile($pattern);
            $this->routes[$pattern] = ['pattern' => $pattern, 'regex' => $regex, 'params' => $params, 'score' => $score, 'methods' => []];
            $this->sorted = false;
        }
        foreach ((array) $methods as $method) {
            $this->routes[$pattern]['methods'][strtoupper($method)] = [
                'handler' => $handler,
                'middleware' => [...$this->groupMiddleware, ...$middleware],
            ];
        }
        return $this;
    }

    public function get(string $pattern, callable $handler, array $middleware = []): self
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable $handler, array $middleware = []): self
    {
        return $this->add('POST', $pattern, $handler, $middleware);
    }

    /** Registers routes inside $fn with $middleware applied to each. */
    public function group(array $middleware, callable $fn): void
    {
        $saved = $this->groupMiddleware;
        $this->groupMiddleware = [...$saved, ...$middleware];
        try {
            $fn($this);
        } finally {
            $this->groupMiddleware = $saved;
        }
    }

    public function has(string $method, string $pattern): bool
    {
        return isset($this->routes[$pattern]['methods'][strtoupper($method)]);
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return array_keys($this->routes);
    }

    /**
     * @return array{status: 200, handler: callable, middleware: list<callable>, params: array<string, string>, pattern: string}|array{status: 404|405, allow?: list<string>}
     */
    public function match(string $method, string $path): array
    {
        $this->sort();
        $method = strtoupper($method);
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = rawurldecode($m[$i + 1]);
            }
            $entry = $route['methods'][$method]
                ?? ($method === 'HEAD' ? ($route['methods']['GET'] ?? null) : null);
            if ($entry === null) {
                return ['status' => 405, 'allow' => array_keys($route['methods'])];
            }
            return ['status' => 200, 'handler' => $entry['handler'], 'middleware' => $entry['middleware'], 'params' => $params, 'pattern' => $route['pattern']];
        }
        return ['status' => 404];
    }

    public function dispatch(Request $request): Response
    {
        $match = $this->match($request->method, $request->path);
        if ($match['status'] === 404) {
            return $request->wantsJson() ? Response::notFound() : ErrorPage::render(404);
        }
        if ($match['status'] === 405) {
            $response = Response::error('Method not allowed', 405);
            $response->header('Allow', implode(', ', $match['allow'] ?? []));
            return $response;
        }
        $request->withAttribute('route', $match['pattern']);
        $params = $match['params'];
        $handler = $match['handler'];
        $core = static fn (Request $r): Response => $handler($r, $params);
        $pipeline = array_reduce(
            array_reverse($match['middleware']),
            static fn (callable $next, callable $mw) => static fn (Request $r): Response => $mw($r, $next),
            $core,
        );
        return $pipeline($request);
    }

    /** @return array{0: string, 1: list<string>, 2: string} */
    private static function compile(string $pattern): array
    {
        if ($pattern === '/') {
            return ['#^/$#', [], ''];
        }
        $params = [];
        $regex = '';
        $score = '';
        foreach (explode('/', trim($pattern, '/')) as $segment) {
            if (preg_match('/^\[\.\.\.([a-zA-Z_][a-zA-Z0-9_]*)\]$/', $segment, $m)) {
                // A catch-all: the rest of the path, slashes included.
                $params[] = $m[1];
                $regex .= '/(.+)';
                $score .= '0';
                continue;
            }
            if (preg_match('/^\[([a-zA-Z_][a-zA-Z0-9_]*)\](.*)$/', $segment, $m)) {
                $params[] = $m[1];
                $regex .= '/([^/]+)' . preg_quote($m[2], '#');
                $score .= $m[2] === '' ? '1' : '2';
            } else {
                $regex .= '/' . preg_quote($segment, '#');
                $score .= '3';
            }
        }
        return ['#^' . $regex . '$#', $params, $score];
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }
        // Most specific first: compare segment by segment, literal over
        // parameter, then longer over shorter.
        uasort($this->routes, static fn ($a, $b) => strcmp($b['score'], $a['score']));
        $this->sorted = true;
    }
}
