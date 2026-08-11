<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_lifecycle_helper;

class change_student_programme extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return transfer_student_programme::execute_parameters();
    }

    public static function execute(
        int $userid,
        int $schoolid,
        int $departmentid,
        int $programmeid,
        int $curriculumid,
        int $curriculumyearid = 0,
        int $curriculumsemesterid = 0,
        int $academicyearid = 0,
        int $semesterid = 0,
        int $effectivedate = 0,
        string $reason = '',
        string $notes = '',
        string $enrolmentpolicy = 'preserve',
        string $courseidstounenrol = '',
        string $courseidstoenrol = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'schoolid', 'departmentid', 'programmeid', 'curriculumid',
            'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid',
            'effectivedate', 'reason', 'notes', 'enrolmentpolicy', 'courseidstounenrol', 'courseidstoenrol'
        ));
        academic_auth_helper::require_manage_academic();
        $result = student_lifecycle_helper::change_programme($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'moodleuserid' => $result['moodleuserid'],
            'profile' => $result['profile'],
            'history' => $result['history'],
            'previous' => $result['previous'],
            'enrolment' => $result['enrolment'],
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_lifecycle_helper::lifecycle_result_structure_keys());
    }
}
