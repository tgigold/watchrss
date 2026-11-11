<?php

declare(strict_types=1);

namespace WatchRss\Http;

/**
 * Minimaler Regex-Router.
 *
 * Routen werden in der Reihenfolge ihrer Registrierung geprüft; benannte
 * Gruppen landen als Parameter-Array beim Handler. Jede Route gibt
 * ausdrücklich an, wer sie aufrufen darf (Access), jede POST-Route
 * zusätzlich ihren CSRF-Schutz (Csrf) - beides ist Pflicht.
 */
final class Router
{
    /** @var list<array{methods:list<string>,pattern:string,handler:callable,access:Access,csrf:?Csrf}> */
    private array $routes = [];

    /**
     * @param callable(Request,array<string,string>):Response $handler
     */
    public function get(string $pattern, callable $handler, Access $access): void
    {
        $this->routes[] = ['methods' => ['GET', 'HEAD'], 'pattern' => $pattern, 'handler' => $handler, 'access' => $access, 'csrf' => null];
    }

    /**
     * @param callable(Request,array<string,string>):Response $handler
     */
    public function post(string $pattern, callable $handler, Access $access, Csrf $csrf): void
    {
        $this->routes[] = ['methods' => ['POST'], 'pattern' => $pattern, 'handler' => $handler, 'access' => $access, 'csrf' => $csrf];
    }

    /**
     * Übersicht für Doku und Tests.
     *
     * @return list<array{methods:list<string>,pattern:string,access:Access,csrf:?Csrf}>
     */
    public function routes(): array
    {
        return array_map(
            static fn (array $route): array => [
                'methods' => $route['methods'],
                'pattern' => $route['pattern'],
                'access'  => $route['access'],
                'csrf'    => $route['csrf'],
            ],
            $this->routes,
        );
    }

    /**
     * Sucht die passende Route, prüft den CSRF-Schutz und führt sie aus.
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

            if ($route['csrf'] !== null) {
                $rejected = $this->checkCsrf($request, $route['csrf']);
                if ($rejected !== null) {
                    return $rejected;
                }
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

    private function checkCsrf(Request $request, Csrf $csrf): ?Response
    {
        if (!$request->isSameOrigin()) {
            return $this->reject($request, 'Anfrage von fremder Seite abgelehnt.', 403);
        }

        if ($csrf === Csrf::JsonBody && !$request->hasJsonBody()) {
            return $this->reject($request, 'Erwartet wird Content-Type application/json.', 415);
        }

        return null;
    }

    private function reject(Request $request, string $message, int $status): Response
    {
        if (str_starts_with($request->path, '/api/') || $request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }

        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
