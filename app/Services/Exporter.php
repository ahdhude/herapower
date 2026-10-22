<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Response;

/** CSV / Excel / PDF output of the generic report table (see ReportBuilder). */
final class Exporter
{
    public static function available(string $format): bool
    {
        return match ($format) {
            'csv'  => true,
            'xlsx' => class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) && extension_loaded('zip'),
            'pdf'  => class_exists(\Dompdf\Dompdf::class),
            default => false,
        };
    }

    public static function filename(array $t, string $ext): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $t['title'] . ' ' . $t['subtitle']) ?? 'report');
        return trim($base, '-') . '.' . $ext;
    }

    public static function csv(array $t): Response
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
        fputcsv($fh, [$t['title'] . ' — ' . $t['subtitle']]);
        fputcsv($fh, array_column($t['cols'], 'label'));
        foreach (array_merge($t['rows'], $t['foot'] ?? []) as $row) {
            fputcsv($fh, array_map(static fn ($v) => $v === null ? '' : (is_float($v) ? round($v, 3) : $v), $row));
        }
        rewind($fh);
        $body = (string) stream_get_contents($fh);
        fclose($fh);
        return Response::download($body, self::filename($t, 'csv'), 'text/csv; charset=utf-8');
    }

    public static function xlsx(array $t): Response
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $t['title']) ?? 'Report', 0, 31));
        $sheet->setCellValue('A1', $t['title'] . ' — ' . $t['subtitle']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $r = 3;
        foreach ($t['cols'] as $i => $c) {
            $sheet->setCellValue([$i + 1, $r], $c['label']);
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($t['cols']));
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType('solid')->getStartColor()->setRGB('E8EEF9');

        foreach (array_merge($t['rows'], $t['foot'] ?? []) as $ri => $row) {
            $r++;
            foreach ($row as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $sheet->setCellValue([$i + 1, $r], is_float($v) ? round($v, 3) : $v);
                if (is_float($v) || is_int($v)) {
                    $dp = (int) ($t['cols'][$i]['dp'] ?? 1);
                    $sheet->getStyle([$i + 1, $r])->getNumberFormat()->setFormatCode($dp > 0 ? '#,##0.' . str_repeat('0', $dp) : '#,##0');
                }
            }
            if ($ri >= count($t['rows'])) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
            }
        }
        foreach (range(1, count($t['cols'])) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->freezePane('B4');

        $w = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        ob_start();
        $w->save('php://output');
        $body = (string) ob_get_clean();
        return Response::download($body, self::filename($t, 'xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public static function pdf(array $t): Response
    {
        $html = \App\Core\View::render('reports/pdf', ['t' => $t], null);
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', count($t['cols']) > 9 ? 'landscape' : 'portrait');
        $dompdf->render();
        return Response::download($dompdf->output(), self::filename($t, 'pdf'), 'application/pdf');
    }
}
