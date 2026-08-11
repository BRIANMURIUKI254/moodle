<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\staff_org_helper;

class update_staff_org extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Assignment id'),
            'schoolid' => new external_value(PARAM_INT, 'School id (0 leaves unchanged)', VALUE_DEFAULT, 0),
            'isprimary' => new external_value(PARAM_INT, 'Primary flag (-1 leaves unchanged)', VALUE_DEFAULT, -1),
            'jobtitle' => new external_value(PARAM_TEXT, 'Job title', VALUE_DEFAULT, ''),
            'updatejobtitle' => new external_value(PARAM_BOOL, 'Apply job title field', VALUE_DEFAULT, false),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(
        int $id, int $schoolid = 0, int $isprimary = -1, string $jobtitle = '',
        bool $updatejobtitle = false, string $status = ''
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'schoolid' => $schoolid, 'isprimary' => $isprimary,
            'jobtitle' => $jobtitle, 'updatejobtitle' => $updatejobtitle, 'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($schoolid > 0) { $input['schoolid'] = $schoolid; }
        if ($isprimary >= 0) { $input['isprimary'] = $isprimary; }
        if ($updatejobtitle) { $input['jobtitle'] = $jobtitle; }
        if ($status !== '') { $input['status'] = $status; }
        $result = staff_org_helper::update($id, $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(staff_org_helper::structure_keys());
    }
}
