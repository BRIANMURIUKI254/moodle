<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\curriculum_structure_helper;

class create_curriculum_year extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'yearnumber' => new external_value(PARAM_INT, 'Year of study number (1-based)'),
            'name' => new external_value(PARAM_TEXT, 'Display name', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
        ]);
    }
    public static function execute(
        int $curriculumid, int $yearnumber, string $name = '', string $description = '', string $status = 'active'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'curriculumid' => $curriculumid, 'yearnumber' => $yearnumber, 'name' => $name,
            'description' => $description, 'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = curriculum_structure_helper::create_year($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_structure_helper::year_structure_keys());
    }
}
