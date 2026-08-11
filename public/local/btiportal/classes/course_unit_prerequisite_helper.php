<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Course Unit prerequisite graph with cycle detection.
 */
class course_unit_prerequisite_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function add(int $courseunitid, int $prerequisitecourseunitid, int $userid): array {
        global $DB;

        if ($courseunitid === $prerequisitecourseunitid) {
            return ['ok' => false, 'errors' => [[
                'field' => 'prerequisitecourseunitid',
                'message' => 'A course unit cannot be a prerequisite of itself',
            ]]];
        }

        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        $prereq = $DB->get_record('local_btiportal_course_unit', ['id' => $prerequisitecourseunitid]);
        if (!$unit) {
            return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit not found']]];
        }
        if (!$prereq) {
            return ['ok' => false, 'errors' => [[
                'field' => 'prerequisitecourseunitid',
                'message' => 'Prerequisite course unit not found',
            ]]];
        }
        if ((int)$unit->programmeid !== (int)$prereq->programmeid
            || (int)$unit->curriculumid !== (int)$prereq->curriculumid) {
            return ['ok' => false, 'errors' => [[
                'field' => 'prerequisitecourseunitid',
                'message' => 'Prerequisite must belong to the same programme and curriculum',
            ]]];
        }
        if ($DB->record_exists('local_btiportal_course_unit_prerequisite', [
            'courseunitid' => $courseunitid,
            'prerequisitecourseunitid' => $prerequisitecourseunitid,
        ])) {
            return ['ok' => false, 'errors' => [[
                'field' => 'prerequisitecourseunitid',
                'message' => 'Prerequisite already exists',
            ]]];
        }

        if (self::would_create_cycle($courseunitid, $prerequisitecourseunitid)) {
            return ['ok' => false, 'errors' => [[
                'field' => 'prerequisitecourseunitid',
                'message' => 'Adding this prerequisite would create a circular dependency',
            ]]];
        }

        $id = $DB->insert_record('local_btiportal_course_unit_prerequisite', (object)[
            'courseunitid' => $courseunitid,
            'prerequisitecourseunitid' => $prerequisitecourseunitid,
            'timecreated' => time(),
            'createdby' => $userid,
        ]);

        return ['ok' => true, 'record' => self::get($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function remove(int $courseunitid, int $prerequisitecourseunitid): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit_prerequisite', [
            'courseunitid' => $courseunitid,
            'prerequisitecourseunitid' => $prerequisitecourseunitid,
        ]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'prerequisitecourseunitid', 'message' => 'Prerequisite not found']]];
        }
        $formatted = self::format($row);
        $DB->delete_records('local_btiportal_course_unit_prerequisite', ['id' => $row->id]);
        return ['ok' => true, 'record' => $formatted];
    }

    /** @return list<array> */
    public static function list_for_unit(int $courseunitid): array {
        global $DB;
        $rows = $DB->get_records(
            'local_btiportal_course_unit_prerequisite',
            ['courseunitid' => $courseunitid],
            'id ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format($row);
        }
        return $out;
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit_prerequisite', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * True if adding edge unit ← requires ← prereq would create a cycle
     * (i.e. unit is already reachable as a prerequisite ancestor of prereq).
     */
    private static function would_create_cycle(int $courseunitid, int $prerequisitecourseunitid): bool {
        global $DB;
        // Walk prerequisites of $prerequisitecourseunitid; if we reach $courseunitid, cycle.
        $stack = [$prerequisitecourseunitid];
        $seen = [];
        while ($stack) {
            $current = array_pop($stack);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if ($current === $courseunitid) {
                return true;
            }
            $deps = $DB->get_records(
                'local_btiportal_course_unit_prerequisite',
                ['courseunitid' => $current],
                '',
                'prerequisitecourseunitid'
            );
            foreach ($deps as $dep) {
                $stack[] = (int)$dep->prerequisitecourseunitid;
            }
        }
        return false;
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'courseunitid' => (int)$row->courseunitid,
            'prerequisitecourseunitid' => (int)$row->prerequisitecourseunitid,
            'timecreated' => (int)$row->timecreated,
            'createdby' => (int)$row->createdby,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'prerequisitecourseunitid' => new \core_external\external_value(PARAM_INT, 'prerequisitecourseunitid'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'createdby' => new \core_external\external_value(PARAM_INT, 'createdby'),
        ];
    }
}
