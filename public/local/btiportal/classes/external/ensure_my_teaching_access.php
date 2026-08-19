<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\lecturer_teaching_helper;

/**
 * Repair Moodle editingteacher enrolment for Course Units the signed-in lecturer teaches.
 * Course-scoped only. Does not grant site administrator.
 */
class ensure_my_teaching_access extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), []);
        academic_auth_helper::require_lecturer_self_read();
        $result = lecturer_teaching_helper::reconcile_moodle_enrolments((int)$USER->id);
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(lecturer_teaching_helper::reconcile_structure_keys());
    }
}
