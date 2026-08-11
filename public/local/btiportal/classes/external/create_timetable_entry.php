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

class create_timetable_entry extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'departmentid' => new external_value(PARAM_INT, 'departmentid'),
            'sessiondate' => new external_value(PARAM_INT, 'sessiondate'),
            'starttime' => new external_value(PARAM_INT, 'starttime'),
            'endtime' => new external_value(PARAM_INT, 'endtime'),
            'programmeid' => new external_value(PARAM_INT, 'programmeid', VALUE_DEFAULT, 0),
            'courseunitid' => new external_value(PARAM_INT, 'courseunitid', VALUE_DEFAULT, 0),
            'moodlecourseid' => new external_value(PARAM_INT, 'moodlecourseid', VALUE_DEFAULT, 0),
            'lecturerid' => new external_value(PARAM_INT, 'lecturerid', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'academicyearid', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'semesterid', VALUE_DEFAULT, 0),
            'room' => new external_value(PARAM_TEXT, 'room', VALUE_DEFAULT, ''),
            'deliverymode' => new external_value(PARAM_ALPHANUMEXT, 'deliverymode', VALUE_DEFAULT, 'on_campus'),
            'recurrence' => new external_value(PARAM_TEXT, 'recurrence', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status', VALUE_DEFAULT, 'draft'),
            'notes' => new external_value(PARAM_RAW, 'notes', VALUE_DEFAULT, ''),
            'meetinglink' => new external_value(PARAM_RAW, 'meeting link', VALUE_DEFAULT, ''),
            'allowwarningoverride' => new external_value(PARAM_BOOL, 'allowwarningoverride', VALUE_DEFAULT, false),
            'overridereason' => new external_value(PARAM_TEXT, 'overridereason', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $departmentid,
        int $sessiondate,
        int $starttime,
        int $endtime,
        int $programmeid = 0,
        int $courseunitid = 0,
        int $moodlecourseid = 0,
        int $lecturerid = 0,
        int $academicyearid = 0,
        int $semesterid = 0,
        string $room = '',
        string $deliverymode = 'on_campus',
        string $recurrence = '',
        string $status = 'draft',
        string $notes = '',
        string $meetinglink = '',
        bool $allowwarningoverride = false,
        string $overridereason = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'departmentid', 'sessiondate', 'starttime', 'endtime', 'programmeid', 'courseunitid',
            'moodlecourseid', 'lecturerid', 'academicyearid', 'semesterid', 'room', 'deliverymode',
            'recurrence', 'status', 'notes', 'meetinglink', 'allowwarningoverride', 'overridereason'
        ));
        department_auth_helper::require_operational_access($departmentid, 'local/btiportal:managetimetable', true);
        $result = timetable_helper::create($params, (int)$USER->id, $allowwarningoverride);
        if (!$result['ok']) {
            $fail = academic_api_response::fail($result['errors']);
            if (!empty($result['conflicts'])) {
                $fail['data'] = ['conflicts' => $result['conflicts']];
            }
            return academic_api_response::for_external($fail);
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'entry' => $result['record'],
            'conflicts' => $result['conflicts'] ?? [],
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'entry' => new external_single_structure(timetable_helper::structure_keys(), 'entry', VALUE_OPTIONAL),
            'conflicts' => new external_multiple_structure(
                new external_single_structure(timetable_helper::conflict_structure_keys()),
                'conflicts',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
