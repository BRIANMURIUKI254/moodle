<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_delivery_plan_helper;

/** Self-scoped academic delivery plan for the signed-in student. */
class get_my_student_delivery_plan extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $result = student_delivery_plan_helper::get_plan($userid);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        $plan = \local_btiportal\student_portal_helper::sanitize_delivery_plan_for_external($result['plan']);
        return academic_api_response::for_external(academic_api_response::ok($plan));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_delivery_plan_helper::plan_structure_keys());
    }
}
