<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\DB;

/** Finds the meter a spreadsheet header (or row label) refers to, by name, never by position. */
final class MeterIndex
{
    /** Extra spellings seen in the real logsheets. key = normalised alias. */
    private const ALIASES = [
        'HV' => 'HV-NETWORK', 'NETWORK' => 'HV-NETWORK', 'HVNET' => 'HV-NETWORK', 'HVINCOMER' => 'HV-NETWORK',
        'LAUNDRYDRYER' => 'LAUNDRY-DRYERS', 'LAUNDRYIRON' => 'LAUNDRY-IRONS', 'LAUNDRYMACHINE' => 'LAUNDRY-MACHINES',
        'INCINERATORWORKSHOP' => 'INCINERATOR-WS', 'INCINERATORWS' => 'INCINERATOR-WS',
        'HRMOSQUE' => 'HR-MOSQUE', 'HRANDMOSQUE' => 'HR-MOSQUE', 'ITBUILDING' => 'IT-BLDG',
        'STPENGOFFICE' => 'STP-ENG-OFFICE', 'STPENGINEERINGOFFICE' => 'STP-ENG-OFFICE',
    ];

    /** @var array<string,array> */
    private array $byKey = [];
    /** @var array<int,array> */
    private array $byId = [];

    /** @param array[] $meters rows from `meters` */
    public function __construct(array $meters)
    {
        foreach ($meters as $m) {
            $this->byId[(int) $m['id']] = $m;
            foreach ([$m['name'], $m['code']] as $label) {
                $k = self::norm((string) $label);
                if ($k !== '') {
                    $this->byKey[$k] ??= $m;
                }
            }
        }
        foreach (self::ALIASES as $alias => $code) {
            foreach ($this->byId as $m) {
                if ($m['code'] === $code) {
                    $this->byKey[$alias] ??= $m;
                }
            }
        }
    }

    public static function all(): self
    {
        return new self(DB::all('SELECT * FROM meters'));
    }

    public static function norm(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
    }

    public function byId(int $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    /** Exact (normalised) match. */
    public function find(mixed $label, ?array $kinds = null): ?array
    {
        if (!is_string($label)) {
            return null;
        }
        $k = self::norm($label);
        $m = $this->byKey[$k] ?? null;
        return ($m && ($kinds === null || in_array($m['kind'], $kinds, true))) ? $m : null;
    }

    /**
     * Near miss (one typo) when there is exactly one candidate. Returns null if ambiguous.
     * @return array|null the meter
     */
    public function fuzzy(mixed $label, ?array $kinds = null): ?array
    {
        if (!is_string($label)) {
            return null;
        }
        $k = self::norm($label);
        if (strlen($k) < 6) {
            return null;
        }
        $hit = null;
        foreach ($this->byKey as $key => $m) {
            if ($kinds !== null && !in_array($m['kind'], $kinds, true)) {
                continue;
            }
            if (abs(strlen($key) - strlen($k)) <= 1 && levenshtein($k, $key) === 1) {
                if ($hit !== null && (int) $hit['id'] !== (int) $m['id']) {
                    return null;
                }
                $hit = $m;
            }
        }
        return $hit;
    }

    /** @return array[] meters of a group in walk order */
    public function inGroup(int $groupId): array
    {
        $out = array_values(array_filter($this->byId, static fn ($m) => (int) $m['group_id'] === $groupId));
        usort($out, static fn ($a, $b) => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
        return $out;
    }
}
