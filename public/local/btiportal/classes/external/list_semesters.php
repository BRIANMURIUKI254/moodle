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
use local_btiportal\academic_calendar_helper;

class list_semesters extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'academicyearid' => new external_value(PARAM_INT, 'Filter by year', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Filter by status', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(int $academicyearid = 0, string $status = ''): array {
        self::validate_parameters(self::execute_parameters(), compact('academicyearid', 'status'));
        academic_auth_helper::require_view_academic();
        $items = academic_calendar_helper::list_semesters(
            $academicyearid > 0 ? $academicyearid : null,
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
                new external_single_structure(academic_calendar_helper::semester_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
