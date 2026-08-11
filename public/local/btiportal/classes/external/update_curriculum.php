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

class update_curriculum extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Curriculum id'),
            'programmeid' => new external_value(PARAM_INT, 'Programme id', VALUE_DEFAULT, 0),
            'code' => new external_value(PARAM_TEXT, 'Code', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_TEXT, 'Name', VALUE_DEFAULT, ''),
            'version' => new external_value(PARAM_TEXT, 'Version', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
            'effectivefrom' => new external_value(PARAM_INT, 'Effective from', VALUE_DEFAULT, -1),
            'effectiveto' => new external_value(PARAM_INT, 'Effective to', VALUE_DEFAULT, -1),
            'updatedescription' => new external_value(PARAM_BOOL, 'Apply description', VALUE_DEFAULT, false),
        ]);
    }
    public static function execute(
        int $id, int $programmeid = 0, string $code = '', string $name = '', string $version = '',
        string $description = '', string $status = '', int $effectivefrom = -1, int $effectiveto = -1,
        bool $updatedescription = false
    ): array {
        self::validate_parameters(self::execute_parameters(), compact(
            'id', 'programmeid', 'code', 'name', 'version', 'description', 'status',
            'effectivefrom', 'effectiveto', 'updatedescription'
        ));
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($programmeid > 0) { $input['programmeid'] = $programmeid; }
        if ($code !== '') { $input['code'] = $code; }
        if ($name !== '') { $input['name'] = $name; }
        if ($version !== '') { $input['version'] = $version; }
        if ($updatedescription) { $input['description'] = $description; }
        if ($status !== '') { $input['status'] = $status; }
        if ($effectivefrom >= 0) { $input['effectivefrom'] = $effectivefrom; }
        if ($effectiveto >= 0) { $input['effectiveto'] = $effectiveto; }
        $result = curriculum_helper::update($id, $input);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_helper::structure_keys());
    }
}
