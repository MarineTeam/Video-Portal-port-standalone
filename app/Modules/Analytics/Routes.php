<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Audit\Audit;

/**
 * /admin/analytics: what was watched and sung over the last week, month or
 * quarter, and the same figures as a file for a board report.
 */
final class Routes
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $can = Middleware::can($app, 'view_analytics');
        $r->get('/admin/analytics', [$self, 'page'], [$can]);
        $r->get('/api/admin/analytics/export', [$self, 'export'], [$can]);
    }

    public function page(Request $req): Response
    {
        $days = Analytics::days($req->query('days'));
        return $this->app->page('admin/analytics', [
            'title' => 'Analytics',
            'windows' => Analytics::WINDOWS,
            'report' => (new Analytics($this->app->db()))->report($days),
        ], 200, 'layouts/admin');
    }

    /**
     * The same three tables as a CSV or a JSON file.
     *
     * One CSV with a section per table rather than three files: a
     * spreadsheet is opened once, and somebody writing a board report wants
     * the lot in front of them.
     */
    public function export(Request $req): Response
    {
        $days = Analytics::days($req->query('days'));
        $report = (new Analytics($this->app->db()))->report($days);
        $format = $req->query('format') === 'json' ? 'json' : 'csv';
        $name = "analytics-$days-days-" . gmdate('Y-m-d') . ".$format";
        Audit::log($this->app->db(), (string) $this->app->currentUser()->email(), 'analytics.export', 'Site', null, "$days days, $format");

        if ($format === 'json') {
            return Response::json($report)->header('Content-Disposition', 'attachment; filename="' . $name . '"');
        }
        return Response::stream(static function () use ($report): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            $put = static function (array $cells) use ($out): void {
                // A cell starting with =, +, - or @ is a formula to a spreadsheet.
                fputcsv($out, array_map(
                    static fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v,
                    $cells,
                ), ',', '"', '\\');
            };
            $put(['Marine Team analytics']);
            $put(['Window', $report['days'] . ' days']);
            $put(['From', $report['since'] . ' UTC']);
            $put(['Views', $report['views']]);
            $put(['People', $report['viewers']]);
            $put([]);
            $put(['Top series', 'Views']);
            foreach ($report['series'] as $row) {
                $put([$row['title'], $row['views']]);
            }
            $put([]);
            $put(['Top videos', 'Views', 'Watched through']);
            foreach ($report['videos'] as $row) {
                $put([$row['title'], $row['views'], $row['watchedThrough'] === null ? '' : $row['watchedThrough'] . '%']);
            }
            $put([]);
            $put(['Most opened hymns', 'Times']);
            foreach ($report['hymns'] as $row) {
                $put([$row['title'], $row['lookups']]);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }
}
