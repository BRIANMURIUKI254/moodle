<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\department_auth_helper;
use local_btiportal\operational_notification_helper;

class list_operational_notifications extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'departmentid' => new external_value(PARAM_INT, 'departmentid', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $departmentid = 0, string $status = ''): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact('departmentid', 'status'));
        department_auth_helper::require_operational_access(
            $departmentid > 0 ? $departmentid : null,
            'local/btiportal:managedepartmentnotifications'
        );
        $items = operational_notification_helper::list((int)$USER->id, [
            'departmentid' => $departmentid,
            'status' => $status,
        ]);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(operational_notification_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
