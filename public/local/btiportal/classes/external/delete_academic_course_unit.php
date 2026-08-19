<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_course_unit_helper;

class delete_academic_course_unit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Course unit id'),
        ]);
    }

    public static function execute(int $id): array {
        self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        academic_auth_helper::require_manage_academic();
        $result = academic_course_unit_helper::delete($id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_course_unit_helper::structure_keys());
    }
}
