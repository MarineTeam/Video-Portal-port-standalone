<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Core\App;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Url;

/**
 * /cron/run — the one door scheduled work comes through. No token configured
 * is a 503 that runs nothing; a wrong one is a 401.
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $r->add(['GET', 'POST'], '/cron/run', function (Request $req) use ($app): Response {
            $token = $req->query('token') ?? $req->bearerToken();
            $verdict = CronGuard::verdict($app->settings()->string('cron.token'), $token);
            if ($verdict === 'unconfigured') {
                return Response::error('Scheduled jobs are not configured on this site.', 503, 'unconfigured');
            }
            if ($verdict === 'unauthorized') {
                return Response::error('Unauthorized', 401);
            }
            ignore_user_abort(true);
            @set_time_limit(60);
            $isLoopback = $req->query('via') === 'pageview';
            if (!$isLoopback) {
                $app->settings()->set('cron.last_real_at', time());
            }
            $scheduler = Jobs::scheduler($app);
            $ran = $scheduler->run($req->query('job'));
            return Response::json(['ran' => $ran]);
        });
    }

    /**
     * Page-view triggering, WordPress-style: when no real cron has been seen
     * for fifteen minutes and something is due, fire one non-blocking request
     * at /cron/run — at most once a minute per install.
     */
    public static function maybeTriggerFromPageView(App $app): void
    {
        try {
            $settings = $app->settings();
            $token = $settings->string('cron.token');
            if ($token === '') {
                return;
            }
            $lastReal = (int) $settings->get('cron.last_real_at', 0);
            if ($lastReal > time() - 900) {
                return;
            }
            $claimed = $app->db()->run(
                'INSERT INTO {{settings}} (name, value) VALUES (\'cron.pageview_at\', ?)
                 ON DUPLICATE KEY UPDATE value = IF(CAST(JSON_UNQUOTE(value) AS UNSIGNED) < ?, VALUES(value), value)',
                [json_encode(time()), time() - 60],
            )->rowCount();
            if ($claimed === 0 || !Jobs::scheduler($app)->anyDue()) {
                return;
            }
            self::loopback(Url::absolute('/cron/run', ['token' => $token, 'via' => 'pageview']));
        } catch (\Throwable $e) {
            Log::warning('Page-view cron trigger skipped: ' . $e->getMessage());
        }
    }

    /** A request we don't wait for: write it and hang up. Goes only to the configured base URL. */
    private static function loopback(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return;
        }
        $https = ($parts['scheme'] ?? 'http') === 'https';
        $port = $parts['port'] ?? ($https ? 443 : 80);
        $socket = @stream_socket_client(($https ? 'ssl://' : 'tcp://') . $parts['host'] . ':' . $port, $errno, $errstr, 2, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if ($socket === false) {
            return;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        fwrite($socket, "GET $path HTTP/1.1\r\nHost: {$parts['host']}\r\nUser-Agent: MarineTeam-cron\r\nConnection: close\r\n\r\n");
        // Give the server a moment to start handling it, then leave.
        usleep(50_000);
        fclose($socket);
    }
}
