<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\lecturer_department_helper;

class update_lecturer_department extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Assignment id'),
            'membershiprole' => new external_value(PARAM_ALPHANUMEXT, 'Membership role', VALUE_DEFAULT, ''),
            'isprimary' => new external_value(PARAM_INT, 'Primary flag (-1 leaves unchanged)', VALUE_DEFAULT, -1),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
            'isdepartmentleader' => new external_value(PARAM_INT, 'Leader flag (-1 unchanged)', VALUE_DEFAULT, -1),
            'timestart' => new external_value(PARAM_INT, 'timestart (-1 unchanged)', VALUE_DEFAULT, -1),
            'timeend' => new external_value(PARAM_INT, 'timeend (-1 unchanged)', VALUE_DEFAULT, -1),
        ]);
    }
    public static function execute(
        int $id,
        string $membershiprole = '',
        int $isprimary = -1,
        string $status = '',
        int $isdepartmentleader = -1,
        int $timestart = -1,
        int $timeend = -1
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'membershiprole' => $membershiprole,
            'isprimary' => $isprimary,
            'status' => $status,
            'isdepartmentleader' => $isdepartmentleader,
            'timestart' => $timestart,
            'timeend' => $timeend,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($membershiprole !== '') {
            $input['membershiprole'] = $membershiprole;
        }
        if ($isprimary >= 0) {
            $input['isprimary'] = $isprimary;
        }
        if ($status !== '') {
            $input['status'] = $status;
        }
        if ($isdepartmentleader >= 0) {
            $input['isdepartmentleader'] = $isdepartmentleader;
        }
        if ($timestart >= 0) {
            $input['timestart'] = $timestart;
        }
        if ($timeend >= 0) {
            $input['timeend'] = $timeend;
        }
        $result = lecturer_department_helper::update($id, $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(lecturer_department_helper::structure_keys());
    }
}
