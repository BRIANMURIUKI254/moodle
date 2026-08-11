<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\curriculum_delivery_helper;

class get_curriculum_delivery extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
        ]);
    }

    public static function execute(int $curriculumid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['curriculumid' => $curriculumid]);
        academic_auth_helper::require_view_academic();
        $result = curriculum_delivery_helper::get_curriculum_delivery((int)$params['curriculumid']);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_delivery_helper::delivery_structure_keys());
    }
}
