<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal enrolled Moodle course → academic programme mapping for student My Courses.
 * Runs under student self-read auth (no viewacademic).
 */
class student_programme_course_helper {

    /**
     * @return list<array{courseid:int,programmeid:int,programmecode:string,programmename:string}>
     */
    public static function mappings_for_userid(int $userid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        $userid = (int)$userid;
        if ($userid <= 0) {
            return [];
        }

        $courses = enrol_get_users_courses($userid, true, 'id,shortname,fullname');
        if (!$courses) {
            return [];
        }

        $courseids = [];
        $byid = [];
        foreach ($courses as $course) {
            $cid = (int)$course->id;
            if ($cid <= 1) {
                continue;
            }
            $courseids[$cid] = $cid;
            $byid[$cid] = $course;
        }
        if (!$courseids) {
            return [];
        }

        $items = [];
        $mapped = [];

        list($insql, $params) = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_NAMED, 'cid');
        $sql = "SELECT cu.courseid, cu.programmeid, p.code AS programmecode, p.name AS programmename
                  FROM {local_btiportal_course_unit} cu
                  JOIN {local_btiportal_programme} p ON p.id = cu.programmeid
                 WHERE cu.courseid {$insql}
                   AND cu.courseid > 1
                   AND cu.programmeid > 0";
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $row) {
            $cid = (int)$row->courseid;
            if ($cid <= 1 || isset($mapped[$cid])) {
                continue;
            }
            $items[] = [
                'courseid' => $cid,
                'programmeid' => (int)$row->programmeid,
                'programmecode' => (string)$row->programmecode,
                'programmename' => (string)$row->programmename,
            ];
            $mapped[$cid] = true;
        }

        // Enrolled programme container courses (shortname = programme.code).
        foreach ($byid as $cid => $course) {
            if (isset($mapped[$cid])) {
                continue;
            }
            $shortname = trim((string)($course->shortname ?? ''));
            if ($shortname === '') {
                continue;
            }
            $programme = $DB->get_record('local_btiportal_programme', ['code' => $shortname], 'id,code,name');
            if (!$programme) {
                continue;
            }
            $items[] = [
                'courseid' => $cid,
                'programmeid' => (int)$programme->id,
                'programmecode' => (string)$programme->code,
                'programmename' => (string)$programme->name,
            ];
            $mapped[$cid] = true;
        }

        return $items;
    }

    public static function item_structure_keys(): array {
        return [
            'courseid' => new \core_external\external_value(PARAM_INT, 'Enrolled Moodle course id'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'Academic programme id'),
            'programmecode' => new \core_external\external_value(PARAM_TEXT, 'Programme code (e.g. BAC001)'),
            'programmename' => new \core_external\external_value(PARAM_TEXT, 'Programme name'),
        ];
    }
}
