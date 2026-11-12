<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit programme → Moodle container-course mapping.
 *
 * Never infer the container at runtime from programme.code = course.shortname.
 * That match is ambiguous (a unit course can reuse the same shortname).
 */
class programme_container_helper {
    /**
     * Parameterized lookup. Avoids SQL that joins plugin strings to mdl_course.shortname
     * (those tables can use different utf8mb4 collations).
     */
    public static function find_course_by_shortname(string $shortname): ?\stdClass {
        global $DB;
        $shortname = trim($shortname);
        if ($shortname === '') {
            return null;
        }
        $course = $DB->get_record('course', ['shortname' => $shortname], 'id,shortname,fullname,visible,category', IGNORE_MISSING);
        return $course ?: null;
    }

    public static function is_unit_moodle_course(int $courseid): bool {
        global $DB;
        if ($courseid <= 1) {
            return false;
        }
        return $DB->record_exists_select(
            'local_btiportal_course_unit',
            'courseid = :courseid AND courseid > 1',
            ['courseid' => $courseid]
        );
    }

    public static function get_container_courseid(int $programmeid): int {
        global $DB;
        if ($programmeid <= 0) {
            return 0;
        }
        if (!$DB->get_manager()->field_exists('local_btiportal_programme', 'containercourseid')) {
            return 0;
        }
        return (int)$DB->get_field('local_btiportal_programme', 'containercourseid', ['id' => $programmeid]);
    }

    public static function get_container_course(int $programmeid): ?\stdClass {
        global $DB;
        $courseid = self::get_container_courseid($programmeid);
        if ($courseid <= 1) {
            return null;
        }
        $course = $DB->get_record('course', ['id' => $courseid], 'id,shortname,fullname,visible,category', IGNORE_MISSING);
        if (!$course || (int)$course->id <= 1) {
            return null;
        }
        return $course;
    }

    /**
     * @return array{ok:bool,errors:list,programmeid:int,courseid:int,message:string}
     */
    public static function set_container_courseid(int $programmeid, int $courseid, int $actorid = 0): array {
        global $DB;
        if ($programmeid <= 0 || !$DB->record_exists('local_btiportal_programme', ['id' => $programmeid])) {
            return ['ok' => false, 'errors' => [['field' => 'programmeid', 'message' => 'Programme not found']], 'programmeid' => $programmeid, 'courseid' => 0, 'message' => ''];
        }
        if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Moodle course not found']], 'programmeid' => $programmeid, 'courseid' => 0, 'message' => ''];
        }
        if (self::is_unit_moodle_course($courseid)) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'courseid',
                    'message' => 'That Moodle course is already a curriculum unit course and cannot be a programme container.',
                ]],
                'programmeid' => $programmeid,
                'courseid' => $courseid,
                'message' => '',
            ];
        }
        $other = $DB->get_record_select(
            'local_btiportal_programme',
            'containercourseid = :courseid AND id <> :programmeid',
            ['courseid' => $courseid, 'programmeid' => $programmeid]
        );
        if ($other) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'courseid',
                    'message' => 'That Moodle course is already the container for programme ' . $other->code,
                ]],
                'programmeid' => $programmeid,
                'courseid' => $courseid,
                'message' => '',
            ];
        }
        $DB->set_field('local_btiportal_programme', 'containercourseid', $courseid, ['id' => $programmeid]);
        $DB->set_field('local_btiportal_programme', 'timemodified', time(), ['id' => $programmeid]);
        if ($actorid > 0) {
            $DB->set_field('local_btiportal_programme', 'usermodified', $actorid, ['id' => $programmeid]);
        }
        return [
            'ok' => true,
            'errors' => [],
            'programmeid' => $programmeid,
            'courseid' => $courseid,
            'message' => 'Programme container course mapped.',
        ];
    }

    /**
     * Auto-map only when a Moodle course shortname matches the programme code AND
     * that course is not a unit of any programme. Never guess on collisions.
     *
     * @return array{mapped:list,unmapped:list}
     */
    public static function populate_unambiguous(): array {
        global $DB;
        $mapped = [];
        $unmapped = [];
        $programmes = $DB->get_records('local_btiportal_programme', null, 'id ASC', 'id,code,name,containercourseid');
        foreach ($programmes as $programme) {
            $programmeid = (int)$programme->id;
            $existing = (int)$programme->containercourseid;
            if ($existing > 1 && $DB->record_exists('course', ['id' => $existing])) {
                $mapped[] = self::status_row($programme, $existing, 'already_mapped');
                continue;
            }
            $course = self::find_course_by_shortname((string)$programme->code);
            if (!$course) {
                $unmapped[] = self::status_row($programme, 0, 'no_moodle_course');
                continue;
            }
            if (self::is_unit_moodle_course((int)$course->id)) {
                $unmapped[] = self::status_row(
                    $programme,
                    (int)$course->id,
                    'shortname_matches_a_unit_course'
                );
                continue;
            }
            $result = self::set_container_courseid($programmeid, (int)$course->id, 0);
            if (!empty($result['ok'])) {
                $mapped[] = self::status_row($programme, (int)$course->id, 'auto_mapped');
            } else {
                $unmapped[] = self::status_row(
                    $programme,
                    (int)$course->id,
                    (string)($result['errors'][0]['message'] ?? 'refused')
                );
            }
        }
        return ['mapped' => $mapped, 'unmapped' => $unmapped];
    }

    /**
     * Read-only mapping report. Does not write containercourseid.
     *
     * @return array{mapped:list,unmapped:list}
     */
    public static function report_status(): array {
        global $DB;
        $mapped = [];
        $unmapped = [];
        if (!$DB->get_manager()->field_exists('local_btiportal_programme', 'containercourseid')) {
            return ['mapped' => $mapped, 'unmapped' => $unmapped];
        }
        $programmes = $DB->get_records('local_btiportal_programme', null, 'id ASC', 'id,code,name,containercourseid');
        foreach ($programmes as $programme) {
            $existing = (int)$programme->containercourseid;
            if ($existing > 1 && $DB->record_exists('course', ['id' => $existing])) {
                $mapped[] = self::status_row($programme, $existing, 'already_mapped');
                continue;
            }
            $course = self::find_course_by_shortname((string)$programme->code);
            if (!$course) {
                $unmapped[] = self::status_row($programme, 0, 'no_moodle_course');
                continue;
            }
            if (self::is_unit_moodle_course((int)$course->id)) {
                $unmapped[] = self::status_row($programme, (int)$course->id, 'shortname_matches_a_unit_course');
                continue;
            }
            $unmapped[] = self::status_row($programme, (int)$course->id, 'unambiguous_but_not_mapped');
        }
        return ['mapped' => $mapped, 'unmapped' => $unmapped];
    }

    /**
     * @return list<array{field:string,message:string}>
     */
    public static function readiness_warnings(int $programmeid, int $curriculumid = 0): array {
        global $DB;
        $warnings = [];
        if ($programmeid <= 0) {
            return [['field' => 'programmeid', 'message' => 'Programme is required']];
        }
        $programme = $DB->get_record('local_btiportal_programme', ['id' => $programmeid], 'id,code,name', IGNORE_MISSING);
        if (!$programme) {
            return [['field' => 'programmeid', 'message' => 'Programme not found']];
        }
        if (self::get_container_courseid($programmeid) <= 1) {
            $warnings[] = [
                'field' => 'containercourseid',
                'message' => 'Programme ' . $programme->code . ' has no Moodle container course. Final exams cannot be opened until an admin maps one.',
            ];
        }
        if ($curriculumid > 0) {
            $unitcount = 0;
            if ($DB->get_manager()->table_exists('local_btiportal_curriculum_unit')) {
                $unitcount = (int)$DB->count_records('local_btiportal_curriculum_unit', ['curriculumid' => $curriculumid]);
            }
            if ($unitcount === 0) {
                $unitcount = (int)$DB->count_records('local_btiportal_course_unit', ['curriculumid' => $curriculumid]);
            }
            if ($unitcount === 0) {
                $warnings[] = [
                    'field' => 'curriculumid',
                    'message' => 'Curriculum ' . $curriculumid . ' has no course units. Students will have no unit enrolments until units are assigned.',
                ];
            }
        }
        return $warnings;
    }

    /**
     * Integer join of programme → container course. Safe across collation differences.
     *
     * @return list<\stdClass>
     */
    public static function list_mapped_containers(): array {
        global $DB;
        if (!$DB->get_manager()->field_exists('local_btiportal_programme', 'containercourseid')) {
            return [];
        }
        return $DB->get_records_sql(
            "SELECT p.id AS programmeid, p.code AS programmecode, p.name AS programmename,
                    c.id AS courseid, c.shortname, c.fullname
               FROM {local_btiportal_programme} p
               JOIN {course} c ON c.id = p.containercourseid
              WHERE p.containercourseid > 1"
        );
    }

    /**
     * @return array{kind:string,programmeid:int,code:string,name:string,courseid:int,reason:string}
     */
    private static function status_row(\stdClass $programme, int $courseid, string $reason): array {
        return [
            'programmeid' => (int)$programme->id,
            'code' => (string)$programme->code,
            'name' => (string)$programme->name,
            'courseid' => $courseid,
            'reason' => $reason,
        ];
    }
}
