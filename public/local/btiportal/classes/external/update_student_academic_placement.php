<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_lifecycle_helper;

class update_student_academic_placement extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'schoolid' => new external_value(PARAM_INT, 'School id', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Department id', VALUE_DEFAULT, 0),
            'programmeid' => new external_value(PARAM_INT, 'Programme id', VALUE_DEFAULT, 0),
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id', VALUE_DEFAULT, 0),
            'curriculumyearid' => new external_value(PARAM_INT, 'Curriculum year', VALUE_DEFAULT, 0),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Curriculum semester', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester', VALUE_DEFAULT, 0),
            'academicstatus' => new external_value(PARAM_ALPHANUMEXT, 'Academic status', VALUE_DEFAULT, ''),
            'effectivedate' => new external_value(PARAM_INT, 'Effective date', VALUE_DEFAULT, 0),
            'reason' => new external_value(PARAM_TEXT, 'Reason', VALUE_DEFAULT, ''),
            'notes' => new external_value(PARAM_TEXT, 'Notes', VALUE_DEFAULT, ''),
            'enrolmentpolicy' => new external_value(PARAM_ALPHANUMEXT, 'Enrolment policy', VALUE_DEFAULT, 'preserve'),
            'courseidstounenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
            'courseidstoenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $userid,
        int $schoolid = 0,
        int $departmentid = 0,
        int $programmeid = 0,
        int $curriculumid = 0,
        int $curriculumyearid = 0,
        int $curriculumsemesterid = 0,
        int $academicyearid = 0,
        int $semesterid = 0,
        string $academicstatus = '',
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
            'academicstatus', 'effectivedate', 'reason', 'notes',
            'enrolmentpolicy', 'courseidstounenrol', 'courseidstoenrol'
        ));
        // Only pass fields that were meaningfully provided (non-zero / non-empty).
        $input = ['userid' => $params['userid'], 'enrolmentpolicy' => $params['enrolmentpolicy']];
        foreach (['schoolid', 'departmentid', 'programmeid', 'curriculumid',
            'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid', 'effectivedate'] as $f) {
            if ((int)$params[$f] > 0) {
                $input[$f] = (int)$params[$f];
            }
        }
        if ($params['academicstatus'] !== '') {
            $input['academicstatus'] = $params['academicstatus'];
        }
        if ($params['reason'] !== '') {
            $input['reason'] = $params['reason'];
        }
        if ($params['notes'] !== '') {
            $input['notes'] = $params['notes'];
        }
        $input['courseidstounenrol'] = $params['courseidstounenrol'];
        $input['courseidstoenrol'] = $params['courseidstoenrol'];

        academic_auth_helper::require_manage_academic();
        $result = student_lifecycle_helper::update_placement($input, (int)$USER->id);
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
