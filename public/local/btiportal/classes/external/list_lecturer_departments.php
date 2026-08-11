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
use local_btiportal\department_auth_helper;
use local_btiportal\lecturer_department_helper;

class list_lecturer_departments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Optional Moodle user filter', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Optional department filter', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Optional status filter', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(int $userid = 0, int $departmentid = 0, string $status = ''): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'departmentid' => $departmentid,
            'status' => $status,
        ]);
        academic_auth_helper::require_view_academic();
        $requested = (int)$userid;
        $isadmin = is_siteadmin($USER) || department_auth_helper::is_university_admin((int)$USER->id);
        if ($isadmin) {
            $filteruserid = $requested > 0 ? $requested : null;
        } else {
            academic_auth_helper::require_view_lecturer_record(
                $requested > 0 ? $requested : (int)$USER->id
            );
            $filteruserid = (int)$USER->id;
        }
        $items = lecturer_department_helper::list(
            $filteruserid,
            $departmentid > 0 ? $departmentid : null,
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
                new external_single_structure(lecturer_department_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
