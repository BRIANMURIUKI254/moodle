<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\lecturer_department_helper;

class assign_lecturer_department extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Existing Moodle user id'),
            'departmentid' => new external_value(PARAM_INT, 'Department id'),
            'membershiprole' => new external_value(PARAM_ALPHANUMEXT, 'Membership role', VALUE_DEFAULT, 'member'),
            'isprimary' => new external_value(PARAM_BOOL, 'Primary department for this lecturer', VALUE_DEFAULT, false),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
            'isdepartmentleader' => new external_value(PARAM_BOOL, 'Department Leader flag', VALUE_DEFAULT, false),
            'timestart' => new external_value(PARAM_INT, 'Assignment start', VALUE_DEFAULT, 0),
            'timeend' => new external_value(PARAM_INT, 'Assignment end (0 = open)', VALUE_DEFAULT, 0),
        ]);
    }
    public static function execute(
        int $userid,
        int $departmentid,
        string $membershiprole = 'member',
        bool $isprimary = false,
        string $status = 'active',
        bool $isdepartmentleader = false,
        int $timestart = 0,
        int $timeend = 0
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'departmentid' => $departmentid,
            'membershiprole' => $membershiprole,
            'isprimary' => $isprimary,
            'status' => $status,
            'isdepartmentleader' => $isdepartmentleader,
            'timestart' => $timestart,
            'timeend' => $timeend,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = lecturer_department_helper::assign($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(lecturer_department_helper::structure_keys());
    }
}
