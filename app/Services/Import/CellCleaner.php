<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Services\ReadingValidator;

/**
 * Turns one spreadsheet cell into a clean number, nothing, or a problem with a *suggested* fix.
 * Suggestions are never applied automatically — a person accepts them on the review screen.
 */
final class CellCleaner
{
    public const EMPTY = 'empty';
    public const NUMBER = 'number';
    public const BAD = 'bad';

    /**
     * @return array{status:string,value:?string,raw:string,suggestion:?string,reason:string}
     */
    public static function clean(mixed $v): array
    {
        if ($v === null) {
            return self::res(self::EMPTY);
        }
        if (is_bool($v)) {
            return self::res(self::BAD, null, $v ? 'TRUE' : 'FALSE', null, 'A yes/no cell, not a number');
        }
        if (is_int($v) || is_float($v)) {
            if (is_float($v) && (is_nan($v) || is_infinite($v))) {
                return self::res(self::BAD, null, (string) $v, null, 'Not a number');
            }
            $raw = self::fmt((float) $v);
            if ($v < 0) {
                return self::res(self::BAD, null, $raw, self::fmt(abs((float) $v)), 'Negative reading');
            }
            if ((float) $v > ReadingValidator::MAX_VALUE) {
                return self::res(self::BAD, null, $raw, null, 'Number is too large');
            }
            return self::res(self::NUMBER, $raw, $raw);
        }

        $raw = (string) $v;
        $s = trim(str_replace("\xC2\xA0", ' ', $raw));
        if ($s === '') {
            return self::res(self::EMPTY);
        }
        if (ReadingValidator::parseNumber($s) !== null) {
            return self::res(self::NUMBER, $s, $raw); // a clean number stored as text
        }
        if (preg_match('/^-\d+(\.\d+)?$/', $s)) {
            return self::res(self::BAD, null, $raw, substr($s, 1), 'Negative reading');
        }
        if (str_starts_with($s, '#')) {
            return self::res(self::BAD, null, $raw, null, 'Excel error in the cell');
        }
        return self::res(self::BAD, null, $raw, self::suggest($s), 'Not a plain number');
    }

    /** Best guess at what was meant, or null when it isn't obvious. */
    public static function suggest(string $s): ?string
    {
        // 1,5 -> 1.5 ; 1,234 -> 1234 (thousands separator)
        if (preg_match('/^\d+,\d+$/', $s)) {
            return preg_match('/^\d{1,3},\d{3}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }
        $t = preg_replace('/[^0-9.]/', '', $s) ?? '';   // drop stray characters:  23.8/4 -> 23.84 , 2\5.84 -> 25.84
        $t = preg_replace('/\.{2,}/', '.', $t) ?? '';    // 11..21 -> 11.21
        $t = rtrim($t, '.');                             // 7.72. -> 7.72
        if (str_starts_with($t, '.')) {
            $t = '0' . $t;                               // .5 -> 0.5
        }
        if ($t === '' || substr_count($t, '.') > 1) {    // 8.4710.51 looks like two readings jammed together: no guess
            return null;
        }
        return ReadingValidator::parseNumber($t) !== null && $t !== $s ? $t : null;
    }

    private static function fmt(float $f): string
    {
        $s = sprintf('%.3F', $f);
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    private static function res(string $status, ?string $value = null, string $raw = '', ?string $suggestion = null, string $reason = ''): array
    {
        return ['status' => $status, 'value' => $value, 'raw' => $raw, 'suggestion' => $suggestion, 'reason' => $reason];
    }
}
