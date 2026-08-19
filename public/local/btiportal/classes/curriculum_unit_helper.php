<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Curriculum ↔ Course Unit assignments.
 *
 * A curriculum contains many course units. The same course unit (and its Moodle
 * course) may be reused across curricula. This table is the relationship layer;
 * Moodle remains the LMS identity for each unit's courseid.
 */
class curriculum_unit_helper {
    public const TABLE = 'local_btiportal_curriculum_unit';

    public static function table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::TABLE);
    }

    /**
     * @return list<array>
     */
    public static function list_assignments(int $curriculumid): array {
        global $DB;

        if ($curriculumid <= 0 || !$DB->record_exists('local_btiportal_curriculum', ['id' => $curriculumid])) {
            return [];
        }

        if (self::table_exists()) {
            $rows = $DB->get_records(self::TABLE, ['curriculumid' => $curriculumid], 'yearofstudy ASC, semestersequence ASC, sortorder ASC, id ASC');
            $out = [];
            foreach ($rows as $row) {
                $formatted = self::format_assignment($row);
                if ($formatted) {
                    $out[] = $formatted;
                }
            }
            return $out;
        }

        return self::legacy_assignments($curriculumid);
    }

    /**
     * Replace all assignments for a curriculum. Does not delete Course Units or Moodle courses.
     *
     * @param list<array> $items
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function save_assignments(int $curriculumid, array $items, int $userid): array {
        global $DB;

        $curriculum = $DB->get_record('local_btiportal_curriculum', ['id' => $curriculumid]);
        if (!$curriculum) {
            return ['ok' => false, 'errors' => [['field' => 'curriculumid', 'message' => 'Curriculum not found']]];
        }
        if (!self::table_exists()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Curriculum unit assignment table is missing']]];
        }

        $resolved = [];
        $seenunitids = [];
        $seencodes = [];
        foreach (array_values($items) as $index => $item) {
            $parsed = self::resolve_item($curriculum, is_array($item) ? $item : [], $index, $userid);
            if (!empty($parsed['errors'])) {
                return ['ok' => false, 'errors' => $parsed['errors']];
            }
            $unitid = (int)$parsed['courseunitid'];
            $code = strtoupper((string)$parsed['code']);
            if (isset($seenunitids[$unitid])) {
                return ['ok' => false, 'errors' => [[
                    'field' => 'courseunitid',
                    'message' => 'The same Course Unit cannot be assigned twice in one curriculum',
                ]]];
            }
            if ($code !== '' && isset($seencodes[$code])) {
                return ['ok' => false, 'errors' => [[
                    'field' => 'code',
                    'message' => 'Unit code ' . $code . ' is already in this curriculum',
                ]]];
            }
            $seenunitids[$unitid] = true;
            if ($code !== '') {
                $seencodes[$code] = true;
            }
            $resolved[] = $parsed;
        }

        $trans = $DB->start_delegated_transaction();
        try {
            $existing = $DB->get_records(self::TABLE, ['curriculumid' => $curriculumid], 'id ASC');
            $keep = [];
            foreach ($resolved as $item) {
                $year = (int)$item['yearofstudy'];
                $sem = (int)$item['semestersequence'];
                curriculum_structure_helper::ensure_year_and_semester($curriculumid, $year, $sem, $userid);

                $match = null;
                foreach ($existing as $row) {
                    if ((int)$row->courseunitid === (int)$item['courseunitid']) {
                        $match = $row;
                        break;
                    }
                }
                $now = time();
                if ($match) {
                    $match->yearofstudy = $year;
                    $match->semestersequence = $sem;
                    $match->sortorder = (int)$item['sortorder'];
                    $match->unittype = (string)$item['unittype'];
                    $match->isrequired = (int)$item['isrequired'];
                    $match->timemodified = $now;
                    $DB->update_record(self::TABLE, $match);
                    $keep[(int)$match->id] = true;
                } else {
                    $fresh = $DB->get_record(self::TABLE, [
                        'curriculumid' => $curriculumid,
                        'courseunitid' => (int)$item['courseunitid'],
                    ]);
                    if ($fresh) {
                        $fresh->yearofstudy = $year;
                        $fresh->semestersequence = $sem;
                        $fresh->sortorder = (int)$item['sortorder'];
                        $fresh->unittype = (string)$item['unittype'];
                        $fresh->isrequired = (int)$item['isrequired'];
                        $fresh->timemodified = $now;
                        $DB->update_record(self::TABLE, $fresh);
                        $keep[(int)$fresh->id] = true;
                    } else {
                        $id = $DB->insert_record(self::TABLE, (object)[
                            'curriculumid' => $curriculumid,
                            'courseunitid' => (int)$item['courseunitid'],
                            'yearofstudy' => $year,
                            'semestersequence' => $sem,
                            'sortorder' => (int)$item['sortorder'],
                            'unittype' => (string)$item['unittype'],
                            'isrequired' => (int)$item['isrequired'],
                            'timecreated' => $now,
                            'timemodified' => $now,
                        ]);
                        $keep[(int)$id] = true;
                    }
                }

                self::sync_unmapped_unit((int)$item['courseunitid'], $userid);
            }

            foreach ($existing as $row) {
                if (!isset($keep[(int)$row->id])) {
                    $DB->delete_records(self::TABLE, ['id' => (int)$row->id]);
                }
            }
            $trans->allow_commit();
        } catch (\Throwable $e) {
            $trans->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        $assignments = self::list_assignments($curriculumid);
        return [
            'ok' => true,
            'record' => [
                'curriculumid' => $curriculumid,
                'items' => $assignments,
                'count' => count($assignments),
            ],
        ];
    }

    /**
     * Seed junction rows from existing course_unit.curriculumid placements.
     * Does not change Moodle course ids.
     */
    /**
     * Keep the curriculum assignment in sync when a Course Unit is created/updated
     * with a home curriculum. Idempotent — never duplicates the same pair.
     */
    public static function ensure_assignment_for_unit(int $courseunitid): void {
        global $DB;
        if ($courseunitid <= 0 || !self::table_exists()) {
            return;
        }
        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit || (int)$unit->curriculumid <= 0) {
            return;
        }
        $curriculumid = (int)$unit->curriculumid;
        if (!$DB->record_exists('local_btiportal_curriculum', ['id' => $curriculumid])) {
            return;
        }
        $now = time();
        $existing = $DB->get_record(self::TABLE, [
            'curriculumid' => $curriculumid,
            'courseunitid' => $courseunitid,
        ]);
        if ($existing) {
            $existing->yearofstudy = max(1, (int)$unit->yearofstudy);
            $existing->semestersequence = max(1, (int)$unit->semestersequence);
            $existing->unittype = (string)($unit->unittype ?: 'core');
            $existing->isrequired = (int)$unit->isrequired;
            $existing->timemodified = $now;
            $DB->update_record(self::TABLE, $existing);
            return;
        }
        $sort = ((int)$DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {' . self::TABLE . '} WHERE curriculumid = ?',
            [$curriculumid]
        )) + 1;
        $DB->insert_record(self::TABLE, (object)[
            'curriculumid' => $curriculumid,
            'courseunitid' => $courseunitid,
            'yearofstudy' => max(1, (int)$unit->yearofstudy),
            'semestersequence' => max(1, (int)$unit->semestersequence),
            'sortorder' => max(1, $sort),
            'unittype' => (string)($unit->unittype ?: 'core'),
            'isrequired' => (int)$unit->isrequired,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function migrate_from_course_units(): int {
        global $DB;
        if (!self::table_exists()) {
            return 0;
        }
        $inserted = 0;
        $units = $DB->get_records_select('local_btiportal_course_unit', 'curriculumid > 0', [], 'id ASC');
        foreach ($units as $unit) {
            $curriculumid = (int)$unit->curriculumid;
            $courseunitid = (int)$unit->id;
            if ($DB->record_exists(self::TABLE, ['curriculumid' => $curriculumid, 'courseunitid' => $courseunitid])) {
                continue;
            }
            $now = time();
            $DB->insert_record(self::TABLE, (object)[
                'curriculumid' => $curriculumid,
                'courseunitid' => $courseunitid,
                'yearofstudy' => max(1, (int)$unit->yearofstudy),
                'semestersequence' => max(1, (int)$unit->semestersequence),
                'sortorder' => $inserted + 1,
                'unittype' => (string)($unit->unittype ?: 'core'),
                'isrequired' => (int)$unit->isrequired,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $inserted++;
        }
        return $inserted;
    }

    /**
     * @return array{ok:bool,courseunitid?:int,code?:string,yearofstudy?:int,semestersequence?:int,sortorder?:int,unittype?:string,isrequired?:int,errors?:list}
     */
    private static function resolve_item(\stdClass $curriculum, array $item, int $index, int $userid): array {
        global $DB;

        $courseunitid = (int)($item['courseunitid'] ?? 0);
        $code = strtoupper(trim((string)($item['code'] ?? '')));
        $name = trim((string)($item['name'] ?? ''));
        $year = (int)($item['yearofstudy'] ?? 0);
        $sem = (int)($item['semestersequence'] ?? 0);
        $sort = (int)($item['sortorder'] ?? ($index + 1));
        $unittype = strtolower(trim((string)($item['unittype'] ?? 'core')));
        $isrequired = array_key_exists('isrequired', $item) ? (!empty($item['isrequired']) ? 1 : 0) : 1;
        $credithours = (float)($item['credithours'] ?? 0);

        if ($year <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'yearofstudy', 'message' => 'Year of study is required']]];
        }
        if ($sem <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'semestersequence', 'message' => 'Semester is required']]];
        }
        if (!in_array($unittype, academic_constants::UNIT_TYPES, true)) {
            $unittype = 'core';
        }
        if ($sort <= 0) {
            $sort = $index + 1;
        }

        $unit = null;
        if ($courseunitid > 0) {
            $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
            if (!$unit) {
                return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit not found']]];
            }
        } else if ($code !== '') {
            $unit = $DB->get_record_sql(
                "SELECT * FROM {local_btiportal_course_unit} WHERE UPPER(code) = UPPER(:code)",
                ['code' => $code]
            );
        }

        if ($unit) {
            return [
                'ok' => true,
                'courseunitid' => (int)$unit->id,
                'code' => (string)$unit->code,
                'yearofstudy' => $year,
                'semestersequence' => $sem,
                'sortorder' => $sort,
                'unittype' => $unittype,
                'isrequired' => $isrequired,
            ];
        }

        if ($code === '' || $name === '') {
            return ['ok' => false, 'errors' => [[
                'field' => 'code',
                'message' => 'Select an existing Course Unit or provide a new code and name',
            ]]];
        }

        $created = academic_course_unit_helper::create([
            'code' => $code,
            'name' => $name,
            'programmeid' => (int)$curriculum->programmeid,
            'curriculumid' => (int)$curriculum->id,
            'yearofstudy' => $year,
            'semestersequence' => $sem,
            'credithours' => $credithours > 0 ? $credithours : 3,
            'unittype' => $unittype,
            'status' => 'active',
            'isrequired' => $isrequired,
        ], $userid);
        if (empty($created['ok'])) {
            return ['ok' => false, 'errors' => $created['errors'] ?? [['field' => 'code', 'message' => 'Could not create course unit']]];
        }

        return [
            'ok' => true,
            'courseunitid' => (int)$created['record']['id'],
            'code' => (string)$created['record']['code'],
            'yearofstudy' => $year,
            'semestersequence' => $sem,
            'sortorder' => $sort,
            'unittype' => $unittype,
            'isrequired' => $isrequired,
        ];
    }

    /**
     * Create/reuse a Moodle course only when the unit is not already mapped.
     * Never remaps an existing Moodle course id.
     */
    private static function sync_unmapped_unit(int $courseunitid, int $userid): void {
        global $DB;
        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit || (int)$unit->courseid > 1) {
            return;
        }
        moodle_mapping_diagnostics_helper::sync_unit($courseunitid, $userid, false, 0);
    }

    private static function format_assignment(\stdClass $row): ?array {
        global $DB;
        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => (int)$row->courseunitid]);
        if (!$unit) {
            return null;
        }
        $formatted = curriculum_delivery_helper::enrich_unit(academic_course_unit_helper::format($unit));
        $formatted['yearofstudy'] = (int)$row->yearofstudy;
        $formatted['semestersequence'] = (int)$row->semestersequence;
        $formatted['unittype'] = (string)$row->unittype;
        $formatted['isrequired'] = (int)$row->isrequired;
        $formatted['sortorder'] = (int)$row->sortorder;
        $formatted['assignmentid'] = (int)$row->id;
        $formatted['assignmentcurriculumid'] = (int)$row->curriculumid;
        if (($formatted['unittype'] ?? '') === 'elective') {
            $formatted['requirementtype'] = 'elective';
        } else if (!empty($formatted['isrequired'])) {
            $formatted['requirementtype'] = 'required';
        } else {
            $formatted['requirementtype'] = 'optional';
        }
        return $formatted;
    }

    /**
     * @return list<array>
     */
    private static function legacy_assignments(int $curriculumid): array {
        $units = academic_course_unit_helper::list_all(null, null, $curriculumid);
        $out = [];
        $sort = 1;
        foreach ($units as $unit) {
            $formatted = curriculum_delivery_helper::enrich_unit($unit);
            $formatted['sortorder'] = $sort++;
            $formatted['assignmentid'] = 0;
            $formatted['assignmentcurriculumid'] = $curriculumid;
            $out[] = $formatted;
        }
        usort($out, static function (array $a, array $b): int {
            $ay = (int)($a['yearofstudy'] ?? 0);
            $by = (int)($b['yearofstudy'] ?? 0);
            if ($ay !== $by) {
                return $ay <=> $by;
            }
            $as = (int)($a['semestersequence'] ?? 0);
            $bs = (int)($b['semestersequence'] ?? 0);
            if ($as !== $bs) {
                return $as <=> $bs;
            }
            return strcasecmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
        });
        return $out;
    }

    public static function assignment_structure_keys(): array {
        return array_merge(curriculum_delivery_helper::enriched_unit_structure_keys(), [
            'sortorder' => new \core_external\external_value(PARAM_INT, 'Display order within the semester', VALUE_OPTIONAL),
            'assignmentid' => new \core_external\external_value(PARAM_INT, 'Curriculum assignment id', VALUE_OPTIONAL),
            'assignmentcurriculumid' => new \core_external\external_value(PARAM_INT, 'Curriculum id for this assignment', VALUE_OPTIONAL),
        ]);
    }
}
