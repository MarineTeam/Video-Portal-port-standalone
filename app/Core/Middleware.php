<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The route guards. Each returns a middleware callable(Request, next).
 * Pages get a redirect to sign in; API routes get 401/403 JSON.
 */
final class Middleware
{
    public static function member(App $app): callable
    {
        return static function (Request $request, callable $next) use ($app): Response {
            if (!$app->currentUser()->isSignedIn()) {
                return self::unauthenticated($app, $request);
            }
            return $next($request);
        };
    }

    public static function staff(App $app): callable
    {
        return static function (Request $request, callable $next) use ($app): Response {
            $current = $app->currentUser();
            if (!$current->isSignedIn()) {
                return self::unauthenticated($app, $request);
            }
            if (!$current->isStaff()) {
                return $request->wantsJson() ? Response::error('Forbidden', 403) : ErrorPage::render(403);
            }
            return $next($request);
        };
    }

    public static function admin(App $app): callable
    {
        return static function (Request $request, callable $next) use ($app): Response {
            $current = $app->currentUser();
            if (!$current->isSignedIn()) {
                return self::unauthenticated($app, $request);
            }
            if (!$current->isAdmin()) {
                return $request->wantsJson() ? Response::error('Forbidden', 403) : ErrorPage::render(403);
            }
            return $next($request);
        };
    }

    /** A capability held site-wide (or anywhere, for a menu-level page that scopes itself). */
    public static function can(App $app, string $capability, bool $anywhere = false): callable
    {
        return static function (Request $request, callable $next) use ($app, $capability, $anywhere): Response {
            $current = $app->currentUser();
            if (!$current->isSignedIn()) {
                return self::unauthenticated($app, $request);
            }
            $ok = $anywhere ? $current->canAnywhere($capability) : $current->can($capability);
            if (!$ok) {
                return $request->wantsJson() ? Response::error('Forbidden', 403) : ErrorPage::render(403);
            }
            return $next($request);
        };
    }

    /** Per-account and per-address request limit for a public write endpoint. */
    public static function throttle(App $app, string $kind, int $limit, int $window): callable
    {
        return static function (Request $request, callable $next) use ($app, $kind, $limit, $window): Response {
            if (!$request->isSafeMethod()) {
                $limiter = new RateLimiter($app->db());
                $keys = ['ip:' . $request->ip];
                if (($id = $app->currentUser()->id()) !== null) {
                    $keys[] = 'user:' . $id;
                }
                foreach ($keys as $key) {
                    if (!$limiter->hit(RateLimiter::bucket($kind, $key), $limit, $window)) {
                        $response = Response::error('Too many requests. Please wait a moment and try again.', 429);
                        $response->header('Retry-After', (string) $window);
                        return $response;
                    }
                }
            }
            return $next($request);
        };
    }

    private static function unauthenticated(App $app, Request $request): Response
    {
        if ($app->currentUser()->wasRevoked()) {
            return $request->wantsJson() ? Response::error('Access denied', 403) : Response::redirect(Url::to('/access-denied'));
        }
        if ($request->wantsJson()) {
            return Response::error('Sign in required', 401);
        }
        $target = Url::to($request->path) . ($request->query === [] ? '' : '?' . http_build_query($request->query));
        return Response::redirect(Url::to('/auth/login', ['returnTo' => $target]));
    }
}
