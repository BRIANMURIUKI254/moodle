<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\school_helper;

class create_school extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_TEXT, 'School code'),
            'name' => new external_value(PARAM_TEXT, 'School name'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
        ]);
    }
    public static function execute(
        string $code, string $name, string $description = '', string $status = 'active'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'code' => $code, 'name' => $name, 'description' => $description, 'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = school_helper::create($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(school_helper::structure_keys());
    }
}
