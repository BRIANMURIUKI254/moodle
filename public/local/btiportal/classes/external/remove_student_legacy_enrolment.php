<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\enrolment_transition_helper;
use local_btiportal\student_enrolment_audit_helper;

class remove_student_legacy_enrolment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'courseid' => new external_value(PARAM_INT, 'Legacy Moodle course id'),
            'confirm' => new external_value(PARAM_BOOL, 'Explicit confirmation required'),
        ]);
    }

    public static function execute(int $userid, int $courseid, bool $confirm): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact('userid', 'courseid', 'confirm'));
        academic_auth_helper::require_manage_academic();
        $result = student_enrolment_audit_helper::remove_legacy(
            (int)$params['userid'],
            (int)$params['courseid'],
            !empty($params['confirm']),
            (int)$USER->id
        );
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'userid' => new external_value(PARAM_INT, 'userid'),
            'removedcourseid' => new external_value(PARAM_INT, 'removedcourseid'),
            'actorid' => new external_value(PARAM_INT, 'actorid'),
            'userdeleted' => new external_value(PARAM_INT, 'userdeleted'),
            'gradespreserved' => new external_value(PARAM_INT, 'gradespreserved'),
            'transition' => new external_single_structure(
                enrolment_transition_helper::result_structure_keys(),
                'transition',
                VALUE_OPTIONAL
            ),
            'audit' => new external_single_structure(
                student_enrolment_audit_helper::audit_structure_keys(),
                'audit',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
