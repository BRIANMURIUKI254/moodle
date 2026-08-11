<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\school_helper;

class update_school extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'School id'),
            'code' => new external_value(PARAM_TEXT, 'School code', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_TEXT, 'School name', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status (archived to archive)', VALUE_DEFAULT, ''),
            'updatedescription' => new external_value(PARAM_BOOL, 'Apply description field', VALUE_DEFAULT, false),
        ]);
    }
    public static function execute(
        int $id, string $code = '', string $name = '', string $description = '',
        string $status = '', bool $updatedescription = false
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'code' => $code, 'name' => $name, 'description' => $description,
            'status' => $status, 'updatedescription' => $updatedescription,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($code !== '') { $input['code'] = $code; }
        if ($name !== '') { $input['name'] = $name; }
        if ($updatedescription) { $input['description'] = $description; }
        if ($status !== '') { $input['status'] = $status; }
        $result = school_helper::update($id, $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(school_helper::structure_keys());
    }
}
