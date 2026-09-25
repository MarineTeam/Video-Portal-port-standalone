<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Turns every Throwable into a logged line and one of two answers: {error,
 * code} JSON for an API route, the plain error page otherwise. No response
 * carries a stack trace, a query or a file path — except to an ADMIN with
 * debug on.
 */
final class ErrorHandler
{
    private static bool $debug = false;
    private static ?string $pluginsDir = null;

    public static function install(bool $debug, string $pluginsDir): void
    {
        self::$debug = $debug;
        self::$pluginsDir = rtrim($pluginsDir, '/');
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /** Which plugin's code is on the stack, if any: the log names it. */
    public static function pluginOnStack(\Throwable $e): ?string
    {
        if (self::$pluginsDir === null) {
            return null;
        }
        $files = [$e->getFile(), ...array_map(fn ($f) => $f['file'] ?? '', $e->getTrace())];
        foreach ($files as $file) {
            if (str_starts_with($file, self::$pluginsDir . '/')) {
                return explode('/', substr($file, strlen(self::$pluginsDir) + 1))[0];
            }
        }
        return null;
    }

    public static function respond(\Throwable $e, Request $request, bool $viewerIsAdmin = false): Response
    {
        if ($e instanceof ApiError) {
            if ($request->wantsJson()) {
                return Response::error($e->getMessage(), $e->status, $e->errorCode);
            }
            return ErrorPage::render($e->status, $e->status < 500 ? null : null);
        }
        if ($e instanceof ValidationError) {
            return $request->wantsJson()
                ? Response::json(['error' => $e->getMessage(), 'code' => 'invalid', 'fields' => $e->fields], 400)
                : ErrorPage::render(400);
        }
        if (Db::isDuplicate($e)) {
            return $request->wantsJson() ? Response::error('That already exists.', 409, 'conflict') : ErrorPage::render(409);
        }
        if (Db::isForeignKey($e)) {
            return $request->wantsJson() ? Response::error('That refers to something that does not exist.', 400, 'invalid') : ErrorPage::render(400);
        }

        Log::error($e->getMessage(), [
            'request' => $request->id,
            'route' => $request->attribute('route', $request->path),
            'user' => $request->attribute('userId'),
            'plugin' => self::pluginOnStack($e),
            'exception' => $e::class,
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
        $detail = self::$debug && $viewerIsAdmin ? $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() : null;
        if ($request->wantsJson()) {
            $body = ['error' => 'Something went wrong', 'code' => 'server_error'];
            if ($detail !== null) {
                $body['debug'] = $detail;
            }
            return Response::json($body, 500);
        }
        return ErrorPage::render(500, $detail);
    }
}
