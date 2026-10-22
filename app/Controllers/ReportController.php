<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Exporter;
use App\Services\ReportBuilder;
use DateTimeImmutable;

final class ReportController
{
    public function index(Request $req): string
    {
        return View::render('reports/index', ['title' => 'Reports', 'active' => 'reports', 'reports' => ReportBuilder::REPORTS]);
    }

    public function feedersDaily(Request $req): Response|string
    {
        return $this->show($req, 'feeders-daily');
    }

    public function solarDaily(Request $req): Response|string
    {
        return $this->show($req, 'solar-daily');
    }

    public function monthly(Request $req): Response|string
    {
        return $this->show($req, 'monthly');
    }

    public function ranking(Request $req): Response|string
    {
        return $this->show($req, 'feeders-ranking');
    }

    public function genset(Request $req): Response|string
    {
        return $this->show($req, 'genset');
    }

    public function completeness(Request $req): Response|string
    {
        return $this->show($req, 'completeness');
    }

    private function show(Request $req, string $slug): Response|string
    {
        [$label, $icon, $param] = ReportBuilder::REPORTS[$slug];
        $now = now();
        $date = $this->validDate((string) $req->query('date', ''), $now->format('Y-m-d'));
        $month = $this->validMonth((string) $req->query('month', ''), $now->format('Y-m'));

        // Operators see today's daily reports only (spec section 6).
        if (Auth::hasRole('operator')) {
            if ($param === 'month') {
                flash('error', 'Monthly reports are for supervisors and managers.');
                return Response::redirect(url('/reports'));
            }
            $date = $now->format('Y-m-d');
        }

        $table = ReportBuilder::build($slug, $date, $month);
        $format = (string) $req->query('format', 'html');
        if ($format !== 'html') {
            if (!Exporter::available($format)) {
                flash('error', strtoupper($format) . ' export isn\'t available on this server (missing Composer packages or the zip extension).');
                return Response::redirect(url('/reports/' . str_replace('-', '/', $slug)) . '?' . ($param === 'month' ? 'month=' . $month : 'date=' . $date));
            }
            return match ($format) {
                'csv'  => Exporter::csv($table),
                'xlsx' => Exporter::xlsx($table),
                'pdf'  => Exporter::pdf($table),
            };
        }

        return View::render('reports/show', [
            'title'    => $label,
            'active'   => 'reports',
            'slug'     => $slug,
            'param'    => $param,
            'date'     => $date,
            'month'    => $month,
            'maxDate'  => $now->format('Y-m-d'),
            'table'    => $table,
            'formats'  => ['csv', 'xlsx', 'pdf'],
            'can'      => array_combine(['csv', 'xlsx', 'pdf'], array_map([Exporter::class, 'available'], ['csv', 'xlsx', 'pdf'])),
            'path'     => '/reports/' . str_replace('-', '/', $slug),
            'latest'   => $table['rows'] ? null : $this->latestWithData($slug, $param),
        ]);
    }

    private function validDate(string $d, string $default): string
    {
        $p = DateTimeImmutable::createFromFormat('!Y-m-d', $d, site_tz());
        return ($p && $p->format('Y-m-d') === $d) ? $d : $default;
    }

    private function validMonth(string $m, string $default): string
    {
        $p = DateTimeImmutable::createFromFormat('!Y-m', $m, site_tz());
        return ($p && $p->format('Y-m') === $m) ? $m : $default;
    }

    /** Most recent month (or day) that has any data for this kind of report, to offer when the chosen one is empty. */
    private function latestWithData(string $slug, string $param): ?string
    {
        $kind = match (true) {
            str_contains($slug, 'solar') => "m.kind = 'solar'",
            $slug === 'monthly' => "m.kind IN ('water','inverter','generator','fuel')",
            default => "m.kind = 'feeder'",
        };
        $day = \App\Core\DB::val("SELECT MAX(d.day) FROM daily_totals d JOIN meters m ON m.id = d.meter_id WHERE {$kind}");
        if (!$day) {
            return null;
        }
        return $param === 'month' ? substr((string) $day, 0, 7) : (string) $day;
    }
}
