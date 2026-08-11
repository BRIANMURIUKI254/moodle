<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\audit_helper;
use local_btiportal\department_auth_helper;

class list_operational_audit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'departmentid' => new external_value(PARAM_INT, 'departmentid', VALUE_DEFAULT, 0),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'action', VALUE_DEFAULT, ''),
            'entitytype' => new external_value(PARAM_ALPHANUMEXT, 'entitytype', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'limit', VALUE_DEFAULT, 200),
        ]);
    }

    public static function execute(
        int $departmentid = 0,
        string $action = '',
        string $entitytype = '',
        int $limit = 200
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact('departmentid', 'action', 'entitytype', 'limit'));
        $userid = (int)$USER->id;
        // University-wide audit requires manageacademic; department leaders use viewdepartmentaudit.
        if (department_auth_helper::is_university_admin($userid)) {
            department_auth_helper::require_operational_access($departmentid > 0 ? $departmentid : null);
        } else {
            department_auth_helper::require_operational_access(
                $departmentid > 0 ? $departmentid : null,
                'local/btiportal:viewdepartmentaudit'
            );
        }
        $items = audit_helper::list(
            $userid,
            $departmentid > 0 ? $departmentid : null,
            $action !== '' ? $action : null,
            $entitytype !== '' ? $entitytype : null,
            $limit
        );
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(audit_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
