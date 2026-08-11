<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_lifecycle_helper;

class readmit_student extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'effectivedate' => new external_value(PARAM_INT, 'Readmission date'),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id'),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id'),
            'reason' => new external_value(PARAM_TEXT, 'Reason or approval reference'),
            'schoolid' => new external_value(PARAM_INT, 'School id', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Department id', VALUE_DEFAULT, 0),
            'programmeid' => new external_value(PARAM_INT, 'Programme id', VALUE_DEFAULT, 0),
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id', VALUE_DEFAULT, 0),
            'curriculumyearid' => new external_value(PARAM_INT, 'Curriculum year', VALUE_DEFAULT, 0),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Curriculum semester', VALUE_DEFAULT, 0),
            'notes' => new external_value(PARAM_TEXT, 'Notes', VALUE_DEFAULT, ''),
            'enrolmentpolicy' => new external_value(PARAM_ALPHANUMEXT, 'Enrolment policy', VALUE_DEFAULT, 'preserve'),
            'courseidstounenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
            'courseidstoenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $userid,
        int $effectivedate,
        int $academicyearid,
        int $semesterid,
        string $reason,
        int $schoolid = 0,
        int $departmentid = 0,
        int $programmeid = 0,
        int $curriculumid = 0,
        int $curriculumyearid = 0,
        int $curriculumsemesterid = 0,
        string $notes = '',
        string $enrolmentpolicy = 'preserve',
        string $courseidstounenrol = '',
        string $courseidstoenrol = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'effectivedate', 'academicyearid', 'semesterid', 'reason',
            'schoolid', 'departmentid', 'programmeid', 'curriculumid',
            'curriculumyearid', 'curriculumsemesterid', 'notes',
            'enrolmentpolicy', 'courseidstounenrol', 'courseidstoenrol'
        ));
        academic_auth_helper::require_manage_academic();
        $result = student_lifecycle_helper::readmit($params, (int)$USER->id);
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
