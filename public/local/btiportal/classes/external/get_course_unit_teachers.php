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
use local_btiportal\course_unit_teacher_helper;

class get_course_unit_teachers extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseunitid' => new external_value(PARAM_INT, 'Course unit id'),
            'includelegacy' => new external_value(PARAM_BOOL, 'Include local_btiportal_unit overlay', VALUE_DEFAULT, true),
        ]);
    }
    public static function execute(int $courseunitid, bool $includelegacy = true): array {
        self::validate_parameters(self::execute_parameters(), compact('courseunitid', 'includelegacy'));
        $unit = academic_course_unit_helper::get($courseunitid);
        if (!$unit) {
            return academic_api_response::for_external(academic_api_response::fail_field('courseunitid', 'Course unit not found'));
        }
        academic_auth_helper::require_course_unit_read((int)$unit['courseid']);
        $items = course_unit_teacher_helper::list_for_unit($courseunitid, $includelegacy);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(course_unit_teacher_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
