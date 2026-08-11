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
use local_btiportal\programme_helper;

class list_programmes extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Optional status filter', VALUE_DEFAULT, ''),
            'departmentid' => new external_value(PARAM_INT, 'Optional department filter', VALUE_DEFAULT, 0),
        ]);
    }
    public static function execute(string $status = '', int $departmentid = 0): array {
        self::validate_parameters(self::execute_parameters(), [
            'status' => $status,
            'departmentid' => $departmentid,
        ]);
        academic_auth_helper::require_view_academic();
        $items = programme_helper::list($status !== '' ? $status : null, $departmentid > 0 ? $departmentid : null);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(programme_helper::programme_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
