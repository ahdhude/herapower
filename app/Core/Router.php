<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Method + path + allowed roles. `$roles` is null (any signed-in user), a list of roles,
 * or 'public' (no sign-in needed). Controllers re-check capabilities in their services.
 */
final class Router
{
    /** @var array<int,array{0:string,1:string,2:array,3:array|string|null}> */
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler, array|string|null $roles = null): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $roles];
    }

    public function get(string $p, array $h, array|string|null $r = null): void
    {
        $this->add('GET', $p, $h, $r);
    }

    public function post(string $p, array $h, array|string|null $r = null): void
    {
        $this->add('POST', $p, $h, $r);
    }

    public function dispatch(Request $req): Response
    {
        $method = $req->method();
        $path = $req->path();
        $pathMatched = false;

        foreach ($this->routes as [$m, $regex, $handler, $roles]) {
            if (!preg_match($regex, $path, $mt)) {
                continue;
            }
            $pathMatched = true;
            if ($m !== $method) {
                continue;
            }
            $params = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($roles !== 'public') {
                if (!Auth::check()) {
                    return $req->wantsJson()
                        ? Response::json(['status' => 'error', 'code' => 'UNAUTHENTICATED', 'message' => 'Please sign in again.'], 401)
                        : Response::redirect(url('/login') . ($path !== '/' ? '?next=' . rawurlencode($path) : ''));
                }
                if (is_array($roles) && !Auth::hasRole(...$roles)) {
                    return $this->forbidden($req);
                }
            }

            if ($method === 'POST' && !Csrf::verify($req)) {
                return $req->wantsJson()
                    ? Response::json(['status' => 'error', 'code' => 'CSRF', 'message' => 'Session expired. Reload the page.'], 419)
                    : Response::html(View::render('error', ['code' => 419, 'message' => 'Your session expired. Go back, reload the page and try again.'], 'layout_plain'), 419);
            }

            [$class, $action] = $handler;
            $result = (new $class())->$action($req, $params);
            return $this->toResponse($result);
        }

        if ($pathMatched) {
            return $req->wantsJson()
                ? Response::json(['status' => 'error', 'message' => 'Method not allowed'], 405)
                : Response::html(View::render('error', ['code' => 405, 'message' => 'Method not allowed.'], 'layout_plain'), 405);
        }
        return $req->wantsJson()
            ? Response::json(['status' => 'error', 'message' => 'Not found'], 404)
            : Response::html(View::render('error', ['code' => 404, 'message' => 'That page does not exist.'], Auth::check() ? 'layout' : 'layout_plain'), 404);
    }

    private function forbidden(Request $req): Response
    {
        return $req->wantsJson()
            ? Response::json(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'You do not have access to this.'], 403)
            : Response::html(View::render('error', ['code' => 403, 'message' => 'You do not have access to this page.'], 'layout'), 403);
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }
}
