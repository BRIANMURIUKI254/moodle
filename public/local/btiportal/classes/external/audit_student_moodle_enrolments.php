<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_enrolment_audit_helper;

class audit_student_moodle_enrolments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('userid'));
        academic_auth_helper::require_view_academic();
        $result = student_enrolment_audit_helper::audit((int)$params['userid']);
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_enrolment_audit_helper::audit_structure_keys());
    }
}
