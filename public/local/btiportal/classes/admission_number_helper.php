<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Admission numbers of the form BTI/YYYY/00001.
 *
 * The next value is derived from committed Moodle user.idnumber rows.
 * Previewing does not reserve a number. Allocation happens under a lock
 * immediately before the user row is inserted.
 */
class admission_number_helper {
    public const LOCK_FACTORY = 'local_btiportal_admission';
    public const LOCK_KEY = 'admission_number';

    /**
     * Next unused number for display. Does not persist or increment a counter.
     *
     * @return array{admissionnumber:string,year:int,sequence:int,preview:bool}
     */
    public static function preview(?int $year = null): array {
        $year = $year ?: (int)date('Y');
        $sequence = self::next_sequence($year);
        return [
            'admissionnumber' => self::format($year, $sequence),
            'year' => $year,
            'sequence' => $sequence,
            'preview' => true,
        ];
    }

    /**
     * Next unused number. Caller must insert the Moodle user before releasing the lock.
     */
    public static function next_unused(?int $year = null): string {
        $year = $year ?: (int)date('Y');
        return self::format($year, self::next_sequence($year));
    }

    /**
     * Acquire the admission-number lock (or null if the factory cannot).
     *
     * @return \core\lock\lock|null
     */
    public static function acquire_lock(int $timeout = 15) {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_FACTORY);
        $lock = $factory->get_lock(self::LOCK_KEY, $timeout);
        return $lock ?: null;
    }

    public static function format(int $year, int $sequence): string {
        return sprintf('BTI/%d/%05d', $year, max(1, $sequence));
    }

    /**
     * True when the value is already used by a non-deleted Moodle user.
     */
    public static function is_taken(string $admissionnumber): bool {
        global $DB;

        $admissionnumber = trim($admissionnumber);
        if ($admissionnumber === '') {
            return false;
        }
        return $DB->record_exists_select(
            'user',
            "deleted = 0 AND idnumber <> '' AND " . $DB->sql_equal('idnumber', ':idnumber', false),
            ['idnumber' => $admissionnumber]
        );
    }

    private static function next_sequence(int $year): int {
        global $DB;

        $prefix = 'BTI/' . $year . '/';
        $like = $DB->sql_like('idnumber', ':pat', false);
        $sql = "SELECT idnumber FROM {user} WHERE deleted = 0 AND idnumber <> '' AND {$like}";
        $idnumbers = $DB->get_fieldset_sql($sql, [
            'pat' => $DB->sql_like_escape($prefix) . '%',
        ]);

        $max = 0;
        $pattern = '/^BTI\/' . preg_quote((string)$year, '/') . '\/(\d+)$/';
        foreach ($idnumbers as $idnumber) {
            if (preg_match($pattern, (string)$idnumber, $matches)) {
                $max = max($max, (int)$matches[1]);
            }
        }
        return $max + 1;
    }
}
