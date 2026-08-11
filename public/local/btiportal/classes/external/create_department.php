<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\department_helper;

class create_department extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_INT, 'Owning school id'),
            'code' => new external_value(PARAM_TEXT, 'Department code'),
            'name' => new external_value(PARAM_TEXT, 'Department name'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
        ]);
    }
    public static function execute(
        int $schoolid, string $code, string $name, string $description = '', string $status = 'active'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid, 'code' => $code, 'name' => $name,
            'description' => $description, 'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = department_helper::create($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(department_helper::structure_keys());
    }
}
