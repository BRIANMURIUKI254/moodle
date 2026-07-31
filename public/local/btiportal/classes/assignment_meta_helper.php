<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Portal assessment category metadata for Moodle assignments (CAT / EXAM / ASSIGNMENT).
 * Separate from local_btiportal_unit_gradeitem (grade write routing).
 */
class assignment_meta_helper {
    public const CATEGORY_CAT = 'CAT';
    public const CATEGORY_EXAM = 'EXAM';
    public const CATEGORY_ASSIGNMENT = 'ASSIGNMENT';

    /** @return list<string> */
    public static function allowed_categories(): array {
        return [self::CATEGORY_CAT, self::CATEGORY_EXAM, self::CATEGORY_ASSIGNMENT];
    }

    /**
     * @throws \moodle_exception
     */
    public static function normalize_category(string $category): string {
        $c = strtoupper(trim($category));
        if ($c === 'ASSIGNMENTS') {
            $c = self::CATEGORY_ASSIGNMENT;
        }
        if (!in_array($c, self::allowed_categories(), true)) {
            throw new \moodle_exception(
                'invalidassignmentcategory',
                'local_btiportal',
                '',
                $category
            );
        }
        return $c;
    }

    /**
     * Upsert category meta for an assign instance.
     */
    public static function save(int $assignmentid, int $courseid, int $unitid, string $category): void {
        global $DB;

        $category = self::normalize_category($category);
        $now = time();
        $existing = $DB->get_record('local_btiportal_assignment_meta', [
            'assignmentid' => $assignmentid,
        ], '*', IGNORE_MULTIPLE);

        if ($existing) {
            $existing->courseid = $courseid;
            $existing->unitid = $unitid;
            $existing->category = $category;
            $existing->timemodified = $now;
            $DB->update_record('local_btiportal_assignment_meta', $existing);
            return;
        }

        $DB->insert_record('local_btiportal_assignment_meta', (object)[
            'assignmentid' => $assignmentid,
            'courseid' => $courseid,
            'unitid' => $unitid,
            'category' => $category,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * @param list<int> $assignmentids
     * @return array<int, array{assignmentid:int, courseid:int, unitid:int, category:string}>
     */
    public static function get_by_assignmentids(array $assignmentids): array {
        global $DB;

        $assignmentids = array_values(array_unique(array_filter(array_map('intval', $assignmentids))));
        if (empty($assignmentids)) {
            return [];
        }

        self::purge_orphans_for_ids($assignmentids);

        list($insql, $params) = $DB->get_in_or_equal($assignmentids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select('local_btiportal_assignment_meta', "assignmentid {$insql}", $params);

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->assignmentid] = [
                'assignmentid' => (int)$r->assignmentid,
                'courseid' => (int)$r->courseid,
                'unitid' => (int)$r->unitid,
                'category' => (string)$r->category,
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $courseids
     * @return list<array{assignmentid:int, courseid:int, unitid:int, category:string}>
     */
    public static function get_by_courseids(array $courseids): array {
        global $DB;

        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        if (empty($courseids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select('local_btiportal_assignment_meta', "courseid {$insql}", $params, 'assignmentid ASC');

        $ids = array_map(static fn($r) => (int)$r->assignmentid, $rows);
        self::purge_orphans_for_ids($ids);

        // Re-read after purge.
        $rows = $DB->get_records_select('local_btiportal_assignment_meta', "courseid {$insql}", $params, 'assignmentid ASC');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'assignmentid' => (int)$r->assignmentid,
                'courseid' => (int)$r->courseid,
                'unitid' => (int)$r->unitid,
                'category' => (string)$r->category,
            ];
        }
        return $out;
    }

    public static function delete_for_assignment(int $assignmentid): void {
        global $DB;
        if ($assignmentid <= 0) {
            return;
        }
        $DB->delete_records('local_btiportal_assignment_meta', ['assignmentid' => $assignmentid]);
    }

    /**
     * Remove meta rows whose assign instance no longer exists.
     *
     * @param list<int> $assignmentids
     */
    public static function purge_orphans_for_ids(array $assignmentids): void {
        global $DB;

        $assignmentids = array_values(array_unique(array_filter(array_map('intval', $assignmentids))));
        if (empty($assignmentids)) {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal($assignmentids, SQL_PARAMS_NAMED);
        $existing = $DB->get_records_select('assign', "id {$insql}", $params, '', 'id');
        $alive = [];
        foreach ($existing as $r) {
            $alive[(int)$r->id] = true;
        }

        foreach ($assignmentids as $aid) {
            if (!isset($alive[$aid])) {
                self::delete_for_assignment($aid);
            }
        }
    }

    /**
     * Human label for UI (empty → Uncategorized).
     */
    public static function display_label(string $category): string {
        $c = strtoupper(trim($category));
        return match ($c) {
            self::CATEGORY_CAT => 'CAT',
            self::CATEGORY_EXAM => 'Exam',
            self::CATEGORY_ASSIGNMENT => 'Assignment',
            default => 'Uncategorized',
        };
    }
}
