<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\curriculum_delivery_helper;

class remove_course_unit_moodle_course extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseunitid' => new external_value(PARAM_INT, 'Course unit id'),
        ]);
    }

    public static function execute(int $courseunitid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['courseunitid' => $courseunitid]);
        academic_auth_helper::require_manage_academic();
        $result = curriculum_delivery_helper::unmap_moodle_course((int)$params['courseunitid'], (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_delivery_helper::enriched_unit_structure_keys());
    }
}
