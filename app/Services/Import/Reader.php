<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Opens one file, works out what kind it is and which month it covers, and runs the matching parser. */
final class Reader
{
    public const KINDS = ['feeders' => 'Feeder Reading (hourly kW)', 'solar' => 'Solar log (half-hourly kW)', 'water_energy' => 'Water & Energy (daily meters + diesel)', 'sql' => 'Old meter_readings SQL export'];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'agu' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public static function ready(): bool
    {
        return class_exists(IOFactory::class) && extension_loaded('zip');
    }

    public static function open(string $path): Spreadsheet
    {
        @ini_set('memory_limit', '768M');
        @set_time_limit(300);
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        return $reader->load($path);
    }

    /** feeders | solar | water_energy | sql | null (unknown) */
    public static function detectKind(string $path, ?Spreadsheet $ss = null): ?string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql') {
            return 'sql';
        }
        $ss ??= self::open($path);
        $titles = array_map(static fn ($t) => strtolower(trim($t)), $ss->getSheetNames());
        if (array_intersect($titles, ['water', 'electricity', 'statistics'])) {
            return 'water_energy';
        }
        foreach ($ss->getAllSheets() as $sh) {
            if (stripos($sh->getTitle(), 'final') !== false || stripos((string) $sh->getCell('A1')->getValue(), 'SOLAR LOG') !== false) {
                return 'solar';
            }
        }
        $idx = MeterIndex::all();
        foreach (array_slice($ss->getAllSheets(), 0, 3) as $sh) {
            foreach ($sh->rangeToArray('A1:AF8', null, true, false) as $row) {
                $hits = 0;
                foreach ($row as $c) {
                    $hits += $idx->find($c, ['feeder']) ? 1 : 0;
                }
                if ($hits >= 3) {
                    return 'feeders';
                }
            }
        }
        return null;
    }

    /**
     * Month a file most likely covers, from folder and file names ("7.JULY", "8.aug", "solar log agu 2026").
     * @return array{0:?string,1:string} [YYYY-MM or null, how it was worked out]
     */
    public static function guessMonth(string $path): array
    {
        $parts = array_reverse(array_values(array_filter(preg_split('#[\\\\/]+#', $path) ?: [])));
        $year = null;
        $month = null;
        $from = '';
        foreach ($parts as $seg) {
            if ($year === null && preg_match('/(?<!\d)(20\d{2})(?!\d)/', $seg, $y)) {
                $year = (int) $y[1];
            }
            if ($month === null && preg_match('/(?<![a-z])(jan|feb|mar|apr|may|jun|jul|aug|agu|sep|oct|nov|dec)[a-z]*/i', $seg, $m)) {
                $month = self::MONTHS[strtolower($m[1])];
                $from = $seg;
            }
        }
        if ($year === null || $month === null) {
            return [null, 'Could not read a month and year from the file or folder name'];
        }
        return [sprintf('%04d-%02d', $year, $month), "from “{$from}” and year {$year}"];
    }

    /** True when the path sits in two different month folders (e.g. 2025/June/May), i.e. a copy of another month. */
    public static function isNestedMonthCopy(string $path): bool
    {
        $months = [];
        foreach (preg_split('#[\\\\/]+#', dirname($path)) ?: [] as $seg) {
            if (preg_match('/(?<![a-z])(jan|feb|mar|apr|may|jun|jul|aug|agu|sep|oct|nov|dec)[a-z]*/i', $seg, $m)) {
                $months[self::MONTHS[strtolower($m[1])]] = true;
            }
        }
        return count($months) > 1;
    }

    /**
     * Parse one file for $month. $kind null = detect.
     * @throws \RuntimeException when the file cannot be understood
     */
    public static function parse(string $path, string $month, ?string $kind = null, ?string $displayName = null): Result
    {
        $name = $displayName ?? basename($path);
        $idx = MeterIndex::all();

        if ($kind === 'sql' || ($kind === null && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql')) {
            $res = new Result('sql', $month, $name);
            (new SqlParser($idx))->parse($path, $res);
            SuspectScanner::scan($res, $idx);
            return $res;
        }

        $ss = self::open($path);
        $kind ??= self::detectKind($path, $ss);
        if ($kind === null) {
            throw new \RuntimeException('This does not look like a feeder, solar or Water & Energy logsheet.');
        }
        $res = new Result($kind, $month, $name);

        switch ($kind) {
            case 'feeders':
                (new FeederParser($idx))->parse($ss, $res);
                break;
            case 'solar':
                $plants = array_values(array_filter(DB::all("SELECT * FROM meters WHERE kind = 'solar' ORDER BY active DESC, id"), static fn ($m) => true));
                if (!$plants) {
                    throw new \RuntimeException('No solar plant meter exists yet. Add one under Admin → Meters first.');
                }
                (new SolarParser())->parse($ss, $res, $plants[0]);
                break;
            case 'water_energy':
                (new WaterEnergyParser($idx, [self::class, 'dieselBase']))->parse($ss, $res);
                break;
            default:
                throw new \RuntimeException("Unknown file kind '{$kind}'.");
        }
        $ss->disconnectWorksheets();
        unset($ss);
        SuspectScanner::scan($res, $idx);
        return $res;
    }

    /** Where the running diesel total starts: the reading already on the 1st, else the latest before it, else 0. */
    public static function dieselBase(string $firstDay): float
    {
        $fuel = DB::val("SELECT id FROM meters WHERE code = 'DIESEL-PH'");
        if (!$fuel) {
            return 0.0;
        }
        $v = DB::val('SELECT value FROM readings WHERE meter_id = ? AND reading_at <= ? ORDER BY reading_at DESC LIMIT 1', [$fuel, $firstDay . ' 00:00:00']);
        return $v === null ? 0.0 : (float) $v;
    }
}
