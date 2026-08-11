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

class update_timetable_entry extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'id'),
            'departmentid' => new external_value(PARAM_INT, 'departmentid', VALUE_DEFAULT, -1),
            'sessiondate' => new external_value(PARAM_INT, 'sessiondate', VALUE_DEFAULT, -1),
            'starttime' => new external_value(PARAM_INT, 'starttime', VALUE_DEFAULT, -1),
            'endtime' => new external_value(PARAM_INT, 'endtime', VALUE_DEFAULT, -1),
            'programmeid' => new external_value(PARAM_INT, 'programmeid', VALUE_DEFAULT, -1),
            'courseunitid' => new external_value(PARAM_INT, 'courseunitid', VALUE_DEFAULT, -1),
            'moodlecourseid' => new external_value(PARAM_INT, 'moodlecourseid', VALUE_DEFAULT, -1),
            'lecturerid' => new external_value(PARAM_INT, 'lecturerid', VALUE_DEFAULT, -1),
            'academicyearid' => new external_value(PARAM_INT, 'academicyearid', VALUE_DEFAULT, -1),
            'semesterid' => new external_value(PARAM_INT, 'semesterid', VALUE_DEFAULT, -1),
            'room' => new external_value(PARAM_TEXT, 'room', VALUE_DEFAULT, '__unchanged__'),
            'deliverymode' => new external_value(PARAM_ALPHANUMEXT, 'deliverymode', VALUE_DEFAULT, ''),
            'recurrence' => new external_value(PARAM_TEXT, 'recurrence', VALUE_DEFAULT, '__unchanged__'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status', VALUE_DEFAULT, ''),
            'notes' => new external_value(PARAM_RAW, 'notes', VALUE_DEFAULT, '__unchanged__'),
            'meetinglink' => new external_value(PARAM_RAW, 'meeting link', VALUE_DEFAULT, '__unchanged__'),
            'allowwarningoverride' => new external_value(PARAM_BOOL, 'allowwarningoverride', VALUE_DEFAULT, false),
            'overridereason' => new external_value(PARAM_TEXT, 'overridereason', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $id,
        int $departmentid = -1,
        int $sessiondate = -1,
        int $starttime = -1,
        int $endtime = -1,
        int $programmeid = -1,
        int $courseunitid = -1,
        int $moodlecourseid = -1,
        int $lecturerid = -1,
        int $academicyearid = -1,
        int $semesterid = -1,
        string $room = '__unchanged__',
        string $deliverymode = '',
        string $recurrence = '__unchanged__',
        string $status = '',
        string $notes = '__unchanged__',
        string $meetinglink = '__unchanged__',
        bool $allowwarningoverride = false,
        string $overridereason = ''
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact(
            'id', 'departmentid', 'sessiondate', 'starttime', 'endtime', 'programmeid', 'courseunitid',
            'moodlecourseid', 'lecturerid', 'academicyearid', 'semesterid', 'room', 'deliverymode',
            'recurrence', 'status', 'notes', 'meetinglink', 'allowwarningoverride', 'overridereason'
        ));
        department_auth_helper::require_operational_access(null, 'local/btiportal:managetimetable');
        $input = [];
        foreach ([
            'departmentid', 'sessiondate', 'starttime', 'endtime', 'programmeid', 'courseunitid',
            'moodlecourseid', 'lecturerid', 'academicyearid', 'semesterid',
        ] as $key) {
            if (${$key} >= 0) {
                $input[$key] = ${$key};
            }
        }
        if ($room !== '__unchanged__') {
            $input['room'] = $room;
        }
        if ($deliverymode !== '') {
            $input['deliverymode'] = $deliverymode;
        }
        if ($recurrence !== '__unchanged__') {
            $input['recurrence'] = $recurrence;
        }
        if ($status !== '') {
            $input['status'] = $status;
        }
        if ($notes !== '__unchanged__') {
            $input['notes'] = $notes;
        }
        if ($meetinglink !== '__unchanged__') {
            $input['meetinglink'] = $meetinglink;
        }
        if ($overridereason !== '') {
            $input['overridereason'] = $overridereason;
        }
        $result = timetable_helper::update($id, $input, (int)$USER->id, $allowwarningoverride);
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
