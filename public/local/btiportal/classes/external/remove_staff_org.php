<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\staff_org_helper;

class remove_staff_org extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Assignment id'),
        ]);
    }
    public static function execute(int $id): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        academic_auth_helper::require_manage_academic();
        $result = staff_org_helper::remove($id, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(staff_org_helper::structure_keys());
    }
}
