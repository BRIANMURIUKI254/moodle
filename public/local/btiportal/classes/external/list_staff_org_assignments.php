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
use local_btiportal\staff_org_helper;

class list_staff_org_assignments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Optional Moodle user filter', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Optional department filter', VALUE_DEFAULT, 0),
            'schoolid' => new external_value(PARAM_INT, 'Optional school filter', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Optional status filter', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(
        int $userid = 0, int $departmentid = 0, int $schoolid = 0, string $status = ''
    ): array {
        self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid, 'departmentid' => $departmentid,
            'schoolid' => $schoolid, 'status' => $status,
        ]);
        academic_auth_helper::require_view_academic();
        $items = staff_org_helper::list(
            $userid > 0 ? $userid : null,
            $departmentid > 0 ? $departmentid : null,
            $schoolid > 0 ? $schoolid : null,
            $status !== '' ? $status : null
        );
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(staff_org_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
