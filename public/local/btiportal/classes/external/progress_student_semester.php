<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_academic_progression_helper;

class progress_student_semester extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Optional explicit target semester', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'Target academic year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Target institutional semester', VALUE_DEFAULT, 0),
            'reason' => new external_value(PARAM_TEXT, 'Reason', VALUE_DEFAULT, ''),
            'notes' => new external_value(PARAM_TEXT, 'Notes', VALUE_DEFAULT, ''),
            'enrol' => new external_value(PARAM_BOOL, 'Execute academic enrolment after progression', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        int $userid,
        int $curriculumsemesterid = 0,
        int $academicyearid = 0,
        int $semesterid = 0,
        string $reason = '',
        string $notes = '',
        bool $enrol = false
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'curriculumsemesterid', 'academicyearid', 'semesterid', 'reason', 'notes', 'enrol'
        ));
        academic_auth_helper::require_manage_academic();
        $target = [
            'reason' => $params['reason'],
            'notes' => $params['notes'],
        ];
        if ($params['curriculumsemesterid'] > 0) {
            $target['curriculumsemesterid'] = $params['curriculumsemesterid'];
        }
        if ($params['academicyearid'] > 0) {
            $target['academicyearid'] = $params['academicyearid'];
        }
        if ($params['semesterid'] > 0) {
            $target['semesterid'] = $params['semesterid'];
        }
        $result = student_academic_progression_helper::progress_semester(
            (int)$params['userid'],
            $target,
            (int)$USER->id,
            (bool)$params['enrol']
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['result']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_academic_progression_helper::result_structure_keys());
    }
}
