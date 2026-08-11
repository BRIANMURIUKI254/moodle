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
use local_btiportal\academic_course_unit_helper;

class get_course_units_by_moodle_course extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Optional status', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(int $courseid, string $status = ''): array {
        self::validate_parameters(self::execute_parameters(), compact('courseid', 'status'));
        academic_auth_helper::require_course_unit_read($courseid);
        $items = academic_course_unit_helper::list_by_moodle_course(
            $courseid,
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
                new external_single_structure(academic_course_unit_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
