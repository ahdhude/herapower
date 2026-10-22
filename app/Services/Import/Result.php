<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * What a parser found in one file. Rows are the clean readings; issues are everything a person should look at.
 * rows[]: [meter_id, 'Y-m-d H:i:s', value(string), warning_codes|null, sheet|null, cell|null]
 */
final class Result
{
    /** @var array[] */
    public array $rows = [];
    /** @var array[] */
    public array $issues = [];
    /** @var array[] name, status (used|skipped), reason, cells */
    public array $sheets = [];
    /** @var string[] */
    public array $notes = [];

    public function __construct(public string $kind, public string $month, public string $file)
    {
    }

    public function row(int $meterId, string $at, string $value, ?string $codes = null, ?string $sheet = null, ?string $cell = null): void
    {
        $this->rows[] = [$meterId, $at, $value, $codes, $sheet, $cell];
    }

    public function issue(string $kind, string $sheet, string $cell, string $raw, ?int $meterId, ?string $at, ?string $suggested, string $message): void
    {
        $this->issues[] = [
            'kind' => $kind, 'sheet' => $sheet, 'cell' => $cell, 'raw' => mb_substr($raw, 0, 100),
            'meter_id' => $meterId, 'reading_at' => $at, 'suggested' => $suggested, 'message' => mb_substr($message, 0, 255),
        ];
    }

    public function sheet(string $name, string $status, string $reason = '', int $cells = 0): void
    {
        $this->sheets[] = ['name' => $name, 'status' => $status, 'reason' => $reason, 'cells' => $cells];
    }

    public function note(string $text): void
    {
        $this->notes[] = $text;
    }

    public function daysInMonth(): int
    {
        return (int) date('t', strtotime($this->month . '-01'));
    }

    /** Days of the month that have no reading at all. @return int[] */
    public function missingDays(): array
    {
        $have = [];
        foreach ($this->rows as $r) {
            if (str_starts_with($r[1], $this->month)) {
                $have[(int) substr($r[1], 8, 2)] = true;
            }
        }
        $missing = [];
        for ($d = 1; $d <= $this->daysInMonth(); $d++) {
            if (!isset($have[$d])) {
                $missing[] = $d;
            }
        }
        return $missing;
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind, 'month' => $this->month, 'file' => $this->file, 'rows' => $this->rows, 'issues' => $this->issues, 'sheets' => $this->sheets, 'notes' => $this->notes];
    }

    public static function fromArray(array $a): self
    {
        $r = new self($a['kind'], $a['month'], $a['file']);
        $r->rows = $a['rows'];
        $r->issues = $a['issues'];
        $r->sheets = $a['sheets'];
        $r->notes = $a['notes'];
        return $r;
    }
}
