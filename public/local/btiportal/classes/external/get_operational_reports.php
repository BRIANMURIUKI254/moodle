<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\department_auth_helper;
use local_btiportal\department_reports_helper;

class get_operational_reports extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'departmentid' => new external_value(PARAM_INT, 'departmentid', VALUE_DEFAULT, 0),
            'scope' => new external_value(PARAM_ALPHANUMEXT, 'auto|university|department', VALUE_DEFAULT, 'auto'),
        ]);
    }

    public static function execute(int $departmentid = 0, string $scope = 'auto'): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'departmentid' => $departmentid,
            'scope' => $scope,
        ]);
        $userid = (int)$USER->id;
        if (department_auth_helper::is_university_admin($userid)) {
            department_auth_helper::require_operational_access($departmentid > 0 ? $departmentid : null);
        } else {
            department_auth_helper::require_operational_access(
                $departmentid > 0 ? $departmentid : null,
                'local/btiportal:viewdepartmentreports'
            );
        }
        $data = department_reports_helper::get_reports($userid, $departmentid > 0 ? $departmentid : null, $scope);
        return academic_api_response::for_external(academic_api_response::ok($data));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(department_reports_helper::structure_keys());
    }
}
