<?php

declare(strict_types=1);

namespace WatchRss\Http;

/**
 * Minimaler Regex-Router.
 *
 * Routen werden in der Reihenfolge ihrer Registrierung geprüft; benannte
 * Gruppen landen als Parameter-Array beim Handler.
 */
final class Router
{
    /** @var list<array{methods:list<string>,pattern:string,handler:callable}> */
    private array $routes = [];

    /**
     * @param string|list<string> $methods
     * @param callable(Request,array<string,string>):Response $handler
     */
    public function add(string|array $methods, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'methods' => array_map('strtoupper', (array) $methods),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * @param callable(Request,array<string,string>):Response $handler
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    /**
     * @param callable(Request,array<string,string>):Response $handler
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * Sucht die passende Route und führt sie aus.
     *
     * @param callable(Request):Response $onNotFound
     */
    public function dispatch(Request $request, callable $onNotFound): Response
    {
        $methodMismatch = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $request->path, $matches) !== 1) {
                continue;
            }

            if (!in_array($request->method, $route['methods'], true)) {
                $methodMismatch = true;
                continue;
            }

            // Nur benannte Gruppen durchreichen
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return ($route['handler'])($request, $params);
        }

        if ($methodMismatch) {
            return new Response('Method Not Allowed', 405, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $onNotFound($request);
    }
}
