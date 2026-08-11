<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\staff_org_helper;

class assign_staff_org extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Existing Moodle user id'),
            'schoolid' => new external_value(PARAM_INT, 'School id', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Department id', VALUE_DEFAULT, 0),
            'isprimary' => new external_value(PARAM_BOOL, 'Primary placement', VALUE_DEFAULT, false),
            'jobtitle' => new external_value(PARAM_TEXT, 'Job title', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
        ]);
    }
    public static function execute(
        int $userid, int $schoolid = 0, int $departmentid = 0, bool $isprimary = false,
        string $jobtitle = '', string $status = 'active'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid, 'schoolid' => $schoolid, 'departmentid' => $departmentid,
            'isprimary' => $isprimary, 'jobtitle' => $jobtitle, 'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = staff_org_helper::assign($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(staff_org_helper::structure_keys());
    }
}
