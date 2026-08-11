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

/**
 * Catalogue-wide Course Unit listing for the admin pages.
 * Per-course reads still go through get_course_units_by_moodle_course.
 */
class list_academic_course_units extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Optional status filter', VALUE_DEFAULT, ''),
            'programmeid' => new external_value(PARAM_INT, 'Optional programme filter', VALUE_DEFAULT, 0),
            'curriculumid' => new external_value(PARAM_INT, 'Optional curriculum filter', VALUE_DEFAULT, 0),
        ]);
    }
    public static function execute(string $status = '', int $programmeid = 0, int $curriculumid = 0): array {
        self::validate_parameters(self::execute_parameters(), [
            'status' => $status,
            'programmeid' => $programmeid,
            'curriculumid' => $curriculumid,
        ]);
        academic_auth_helper::require_view_academic();
        $items = academic_course_unit_helper::list_all(
            $status !== '' ? $status : null,
            $programmeid > 0 ? $programmeid : null,
            $curriculumid > 0 ? $curriculumid : null
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
