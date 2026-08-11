<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\department_auth_helper;
use local_btiportal\timetable_helper;

class list_timetable_entries extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'departmentid' => new external_value(PARAM_INT, 'departmentid', VALUE_DEFAULT, 0),
            'programmeid' => new external_value(PARAM_INT, 'programmeid', VALUE_DEFAULT, 0),
            'courseunitid' => new external_value(PARAM_INT, 'courseunitid', VALUE_DEFAULT, 0),
            'moodlecourseid' => new external_value(PARAM_INT, 'moodlecourseid', VALUE_DEFAULT, 0),
            'lecturerid' => new external_value(PARAM_INT, 'lecturerid', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'academicyearid', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'semesterid', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status', VALUE_DEFAULT, ''),
            'datestart' => new external_value(PARAM_INT, 'datestart', VALUE_DEFAULT, 0),
            'dateend' => new external_value(PARAM_INT, 'dateend', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $departmentid = 0,
        int $programmeid = 0,
        int $courseunitid = 0,
        int $moodlecourseid = 0,
        int $lecturerid = 0,
        int $academicyearid = 0,
        int $semesterid = 0,
        string $status = '',
        int $datestart = 0,
        int $dateend = 0
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact(
            'departmentid', 'programmeid', 'courseunitid', 'moodlecourseid', 'lecturerid',
            'academicyearid', 'semesterid', 'status', 'datestart', 'dateend'
        ));
        department_auth_helper::require_operational_access(
            $departmentid > 0 ? $departmentid : null,
            'local/btiportal:managetimetable'
        );
        $items = timetable_helper::list((int)$USER->id, [
            'departmentid' => $departmentid,
            'programmeid' => $programmeid,
            'courseunitid' => $courseunitid,
            'moodlecourseid' => $moodlecourseid,
            'lecturerid' => $lecturerid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'status' => $status,
            'datestart' => $datestart,
            'dateend' => $dateend,
        ]);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(timetable_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
