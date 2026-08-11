<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\department_helper;

class update_department extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Department id'),
            'schoolid' => new external_value(PARAM_INT, 'Owning school id', VALUE_DEFAULT, 0),
            'code' => new external_value(PARAM_TEXT, 'Department code', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_TEXT, 'Department name', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status (archived to archive)', VALUE_DEFAULT, ''),
            'updatedescription' => new external_value(PARAM_BOOL, 'Apply description field', VALUE_DEFAULT, false),
        ]);
    }
    public static function execute(
        int $id, int $schoolid = 0, string $code = '', string $name = '', string $description = '',
        string $status = '', bool $updatedescription = false
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'schoolid' => $schoolid, 'code' => $code, 'name' => $name,
            'description' => $description, 'status' => $status, 'updatedescription' => $updatedescription,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($schoolid > 0) { $input['schoolid'] = $schoolid; }
        if ($code !== '') { $input['code'] = $code; }
        if ($name !== '') { $input['name'] = $name; }
        if ($updatedescription) { $input['description'] = $description; }
        if ($status !== '') { $input['status'] = $status; }
        $result = department_helper::update($id, $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(department_helper::structure_keys());
    }
}
