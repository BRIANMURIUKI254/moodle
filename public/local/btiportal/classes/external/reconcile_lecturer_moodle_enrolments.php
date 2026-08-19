<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\lecturer_teaching_helper;

class reconcile_lecturer_moodle_enrolments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Lecturer Moodle user id; 0 = all assigned lecturers', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $userid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('userid'));
        academic_auth_helper::require_manage_academic();
        $result = lecturer_teaching_helper::reconcile_moodle_enrolments((int)$params['userid']);
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(lecturer_teaching_helper::reconcile_structure_keys());
    }
}
