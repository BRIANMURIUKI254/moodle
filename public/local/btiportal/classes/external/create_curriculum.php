<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\curriculum_helper;

class create_curriculum extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'code' => new external_value(PARAM_TEXT, 'Curriculum code'),
            'name' => new external_value(PARAM_TEXT, 'Curriculum name'),
            'version' => new external_value(PARAM_TEXT, 'Version label'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'draft'),
            'effectivefrom' => new external_value(PARAM_INT, 'Effective from unix', VALUE_DEFAULT, 0),
            'effectiveto' => new external_value(PARAM_INT, 'Effective to unix', VALUE_DEFAULT, 0),
        ]);
    }
    public static function execute(
        int $programmeid, string $code, string $name, string $version,
        string $description = '', string $status = 'draft', int $effectivefrom = 0, int $effectiveto = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'programmeid', 'code', 'name', 'version', 'description', 'status', 'effectivefrom', 'effectiveto'
        ));
        academic_auth_helper::require_manage_academic();
        $result = curriculum_helper::create($params);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_helper::structure_keys());
    }
}
