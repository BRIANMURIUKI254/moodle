<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\curriculum_structure_helper;

class create_curriculum_semester extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumyearid' => new external_value(PARAM_INT, 'Year of study id'),
            'semesternumber' => new external_value(PARAM_INT, 'Semester number within the year'),
            'name' => new external_value(PARAM_TEXT, 'Display name', VALUE_DEFAULT, ''),
            'creditrequirement' => new external_value(PARAM_FLOAT, 'Required credits', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
        ]);
    }
    public static function execute(
        int $curriculumyearid, int $semesternumber, string $name = '',
        float $creditrequirement = 0, string $status = 'active'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'curriculumyearid' => $curriculumyearid, 'semesternumber' => $semesternumber,
            'name' => $name, 'creditrequirement' => $creditrequirement, 'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = curriculum_structure_helper::create_semester($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_structure_helper::semester_structure_keys());
    }
}
