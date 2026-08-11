<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\curriculum_structure_helper;

class update_curriculum_semester extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Curriculum semester id'),
            'semesternumber' => new external_value(PARAM_INT, 'Semester number', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'Display name', VALUE_DEFAULT, ''),
            'creditrequirement' => new external_value(PARAM_FLOAT, 'Required credits', VALUE_DEFAULT, -1),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status (archived to archive)', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(
        int $id, int $semesternumber = 0, string $name = '', float $creditrequirement = -1, string $status = ''
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'semesternumber' => $semesternumber, 'name' => $name,
            'creditrequirement' => $creditrequirement, 'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($semesternumber > 0) { $input['semesternumber'] = $semesternumber; }
        if ($name !== '') { $input['name'] = $name; }
        if ($creditrequirement >= 0) { $input['creditrequirement'] = $creditrequirement; }
        if ($status !== '') { $input['status'] = $status; }
        $result = curriculum_structure_helper::update_semester($id, $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_structure_helper::semester_structure_keys());
    }
}
