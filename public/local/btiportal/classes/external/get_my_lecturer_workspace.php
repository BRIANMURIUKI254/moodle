<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_calendar_helper;
use local_btiportal\lecturer_department_helper;
use local_btiportal\lecturer_teaching_helper;
use local_btiportal\timetable_helper;

/**
 * Self-scoped lecturer teaching workspace for the portal dashboard and teaching pages.
 * Does not expose other lecturers' records.
 */
class get_my_lecturer_workspace extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'academicyearid' => new external_value(PARAM_INT, 'Filter by academic year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Filter by institutional semester', VALUE_DEFAULT, 0),
            'includelegacy' => new external_value(PARAM_BOOL, 'Include unperioded legacy assignments', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        int $academicyearid = 0,
        int $semesterid = 0,
        bool $includelegacy = false
    ): array {
        global $USER, $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'includelegacy' => $includelegacy,
        ]);
        academic_auth_helper::require_lecturer_self_read();
        $userid = (int)$USER->id;

        $years = academic_calendar_helper::list_years('active');
        $currentyear = null;
        foreach ($years as $year) {
            if ((int)($year['iscurrent'] ?? 0) === 1) {
                $currentyear = $year;
                break;
            }
        }
        if ($currentyear === null && $years) {
            $currentyear = $years[0];
        }

        $yearid = (int)$params['academicyearid'];
        if ($yearid <= 0 && $currentyear) {
            $yearid = (int)$currentyear['id'];
        }

        $semesters = $yearid > 0
            ? academic_calendar_helper::list_semesters($yearid, 'active')
            : [];
        $currentsemester = null;
        foreach ($semesters as $sem) {
            if ((int)($sem['iscurrent'] ?? 0) === 1) {
                $currentsemester = $sem;
                break;
            }
        }
        if ($currentsemester === null && $semesters) {
            $currentsemester = $semesters[0];
        }

        $semid = (int)$params['semesterid'];
        if ($semid <= 0 && $currentsemester) {
            $semid = (int)$currentsemester['id'];
        }

        $workload = lecturer_teaching_helper::get_workload(
            $userid,
            $yearid > 0 ? $yearid : null,
            $semid > 0 ? $semid : null,
            (bool)$params['includelegacy']
        );

        $departments = lecturer_department_helper::list($userid, null, 'active');
        $allunits = lecturer_teaching_helper::list_course_units($userid, 'active');
        $moodlecourses = lecturer_teaching_helper::list_moodle_teaching_courses($userid);

        $now = time();
        $weekstart = strtotime('monday this week', $now);
        $weekend = strtotime('sunday this week 23:59:59', $now);
        $mysessions = timetable_helper::list_for_lecturer($userid, [
            'datestart' => (int)$weekstart,
            'dateend' => (int)$weekend,
            'status' => 'scheduled',
        ]);
        $todaysessions = [];
        $todaystart = strtotime('today', $now);
        $todayend = strtotime('tomorrow', $now) - 1;
        foreach ($mysessions as $session) {
            $sd = (int)($session['sessiondate'] ?? 0);
            if ($sd >= $todaystart && $sd <= $todayend) {
                $todaysessions[] = $session;
            }
        }

        $studenttotal = 0;
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', IGNORE_MISSING);
        if ($studentrole && $moodlecourses) {
            foreach ($moodlecourses as $course) {
                if (($course['statuslabel'] ?? '') !== 'active') {
                    continue;
                }
                $courseid = (int)$course['courseid'];
                $ctx = \context_course::instance($courseid, IGNORE_MISSING);
                if (!$ctx) {
                    continue;
                }
                $studenttotal += (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ra.userid)
                       FROM {role_assignments} ra
                       JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                      WHERE ra.contextid = ? AND ra.roleid = ?",
                    [$ctx->id, (int)$studentrole->id]
                );
            }
        }

        $data = [
            'userid' => $userid,
            'fullname' => fullname($USER),
            'email' => (string)$USER->email,
            'username' => (string)$USER->username,
            'phone1' => (string)($USER->phone1 ?? ''),
            'academicyearid' => $yearid,
            'semesterid' => $semid,
            'currentacademicyear' => [
                'id' => $currentyear ? (int)$currentyear['id'] : 0,
                'name' => $currentyear ? (string)$currentyear['name'] : '',
                'code' => $currentyear ? (string)($currentyear['code'] ?? '') : '',
                'iscurrent' => $currentyear ? (int)($currentyear['iscurrent'] ?? 0) : 0,
            ],
            'currentsemester' => [
                'id' => $currentsemester ? (int)$currentsemester['id'] : 0,
                'name' => $currentsemester ? (string)$currentsemester['name'] : '',
                'code' => $currentsemester ? (string)($currentsemester['code'] ?? '') : '',
                'academicyearid' => $currentsemester ? (int)($currentsemester['academicyearid'] ?? 0) : 0,
                'iscurrent' => $currentsemester ? (int)($currentsemester['iscurrent'] ?? 0) : 0,
            ],
            'academicyears' => array_map(static function ($y) {
                return [
                    'id' => (int)$y['id'],
                    'name' => (string)$y['name'],
                    'code' => (string)($y['code'] ?? ''),
                    'iscurrent' => (int)($y['iscurrent'] ?? 0),
                ];
            }, $years),
            'semesters' => array_map(static function ($s) {
                return [
                    'id' => (int)$s['id'],
                    'name' => (string)$s['name'],
                    'code' => (string)($s['code'] ?? ''),
                    'academicyearid' => (int)($s['academicyearid'] ?? 0),
                    'iscurrent' => (int)($s['iscurrent'] ?? 0),
                ];
            }, $semesters),
            'departments' => $departments,
            'courseunits' => $allunits,
            'moodlecourses' => $moodlecourses,
            'workload' => $workload,
            'studenttotal' => $studenttotal,
            'upcomingtimetablesessions' => $mysessions,
            'todaysessions' => $todaysessions,
        ];

        return academic_api_response::for_external(academic_api_response::ok($data));
    }

    public static function execute_returns() {
        $period = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'code' => new external_value(PARAM_TEXT, 'code'),
            'iscurrent' => new external_value(PARAM_INT, 'iscurrent'),
        ]);
        $semester = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'code' => new external_value(PARAM_TEXT, 'code'),
            'academicyearid' => new external_value(PARAM_INT, 'academicyearid'),
            'iscurrent' => new external_value(PARAM_INT, 'iscurrent'),
        ]);

        return academic_api_response::returns_structure([
            'userid' => new external_value(PARAM_INT, 'userid'),
            'fullname' => new external_value(PARAM_TEXT, 'fullname'),
            'email' => new external_value(PARAM_TEXT, 'email'),
            'username' => new external_value(PARAM_TEXT, 'username'),
            'phone1' => new external_value(PARAM_TEXT, 'phone1'),
            'academicyearid' => new external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new external_value(PARAM_INT, 'semesterid'),
            'currentacademicyear' => $period,
            'currentsemester' => $semester,
            'academicyears' => new external_multiple_structure($period),
            'semesters' => new external_multiple_structure($semester),
            'departments' => new external_multiple_structure(
                new external_single_structure(lecturer_department_helper::structure_keys())
            ),
            'courseunits' => new external_multiple_structure(
                new external_single_structure(lecturer_teaching_helper::course_unit_assignment_structure_keys())
            ),
            'moodlecourses' => new external_multiple_structure(
                new external_single_structure(lecturer_teaching_helper::moodle_teaching_structure_keys())
            ),
            'workload' => new external_single_structure(lecturer_teaching_helper::workload_structure_keys()),
            'studenttotal' => new external_value(PARAM_INT, 'studenttotal'),
            'upcomingtimetablesessions' => new external_multiple_structure(
                new external_single_structure(timetable_helper::structure_keys())
            ),
            'todaysessions' => new external_multiple_structure(
                new external_single_structure(timetable_helper::structure_keys())
            ),
        ]);
    }
}
