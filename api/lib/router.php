<?php
declare(strict_types=1);

final class Router
{
    /** @var array<int, array{method:string,regex:string,params:array<int,string>,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $match) use (&$paramNames): string {
            $paramNames[] = $match[1];
            return '([^/]+)';
        }, $pattern);

        if (!is_string($regex)) {
            throw new RuntimeException('Route regex oluşturulamadı.');
        }

        $regex = '#^' . rtrim($regex, '/') . '/?$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $regex,
            'params' => $paramNames,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $normalizedMethod = strtoupper($method);
        $normalizedPath = '/' . ltrim($path, '/');
        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $normalizedMethod) {
                continue;
            }

            if (!preg_match($route['regex'], $normalizedPath, $matches)) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = $matches[$index] ?? null;
            }

            ($route['handler'])($params);
            return;
        }

        error_response('NOT_FOUND', 'İstenen endpoint bulunamadı.', 404);
    }
}
