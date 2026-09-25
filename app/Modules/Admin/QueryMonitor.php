<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\App;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Modules\Audit\Audit;
use App\Modules\Plugins\Features;

/**
 * A debug bar at the foot of every page: the queries this request ran and
 * their time, the page's own time, peak memory. Two switches, both of which
 * must be on:
 *
 *   'query_monitor' => true in storage/config.php — the deploy-level switch,
 *     which also turns recording on in the Db wrapper (off, recording costs
 *     nothing); /admin/query-monitor can only report it;
 *   the admin switch on /admin/query-monitor, stored as the plugins row
 *     "query-monitor" (not a plugin; never listed as one), on until turned
 *     off, so the config flag alone shows the bar.
 *
 * Even then only ADMIN accounts ever see it: SQL and timings hint at schema
 * and data. Each request tallies its own queries (PHP shares nothing between
 * requests), so concurrent requests can't mix their counts.
 */
final class QueryMonitor
{
    public static function configured(App $app): bool
    {
        $flag = $app->config['query_monitor'] ?? false;
        return $flag === true || (is_string($flag) && strtolower(trim($flag)) === 'true');
    }

    public static function switchedOn(App $app): bool
    {
        $enabled = $app->db()->value('SELECT enabled FROM {{plugins}} WHERE slug = ?', [Features::QUERY_MONITOR]);
        return $enabled === null || (bool) $enabled;
    }

    public static function register(Router $r, App $app): void
    {
        $can = Middleware::can($app, 'manage_plugins');
        $r->get('/admin/query-monitor', fn () => $app->page('admin/query-monitor', [
            'title' => 'Query monitor',
            'configured' => self::configured($app),
            'enabled' => self::switchedOn($app),
        ], 200, 'layouts/admin'), [$can]);
        $r->add('PATCH', '/api/admin/query-monitor', function (Request $req) use ($app): Response {
            $enabled = $req->input()['enabled'] ?? null;
            if (!is_bool($enabled)) {
                throw \App\Core\ApiError::invalid('enabled must be true or false.');
            }
            $app->db()->run(
                'INSERT INTO {{plugins}} (id, slug, name, enabled, bundled) VALUES (?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                [Id::new(), Features::QUERY_MONITOR, 'Query monitor', $enabled ? 1 : 0],
            );
            Audit::log($app->db(), (string) $app->currentUser()->email(), $enabled ? 'query_monitor.on' : 'query_monitor.off', 'Plugin', Features::QUERY_MONITOR);
            return Response::json(['enabled' => $enabled, 'configured' => self::configured($app)]);
        }, [$can]);
    }

    /** Adds the bar to an HTML page an administrator is looking at. */
    public static function inject(App $app, Request $request, Response $response): void
    {
        if (!self::configured($app) || !$app->hasDb() || !$app->viewerIsAdmin() || $request->isApi()) {
            return;
        }
        $type = (string) ($response->getHeader('Content-Type') ?? '');
        $end = strripos($response->body, '</body>');
        if (!str_starts_with($type, 'text/html') || $end === false) {
            return;
        }
        try {
            if (!self::switchedOn($app)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        $log = $app->db()->queryLog();
        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $response->body = substr($response->body, 0, $end) . self::bar($log, (microtime(true) - $started) * 1000, memory_get_peak_usage(true)) . substr($response->body, $end);
    }

    /** @param list<array{sql: string, ms: float}> $log */
    public static function bar(array $log, float $elapsedMs, int $peakBytes): string
    {
        $total = array_sum(array_column($log, 'ms'));
        $rows = '';
        foreach ($log as $i => $q) {
            $sql = (string) preg_replace('/\s+/', ' ', $q['sql']);
            $rows .= '<tr><td>' . ($i + 1) . '</td><td class="qm-ms">' . number_format($q['ms'], 2) . '</td><td><code>'
                . View::escape(mb_strlen($sql) > 300 ? mb_substr($sql, 0, 300) . '…' : $sql) . '</code></td></tr>';
        }
        return '<details class="qm-bar"><summary>'
            . count($log) . ' queries · ' . number_format($total, 1) . ' ms in the database · '
            . number_format($elapsedMs, 1) . ' ms in all · ' . number_format($peakBytes / 1048576, 1) . ' MB peak memory'
            . '</summary><table><thead><tr><th>#</th><th>ms</th><th>SQL</th></tr></thead><tbody>' . $rows . '</tbody></table></details>';
    }
}
