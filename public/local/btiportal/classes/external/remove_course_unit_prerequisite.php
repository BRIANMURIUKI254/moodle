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
use local_btiportal\course_unit_prerequisite_helper;

class remove_course_unit_prerequisite extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseunitid' => new external_value(PARAM_INT, 'Course unit id'),
            'prerequisitecourseunitid' => new external_value(PARAM_INT, 'Prerequisite unit id'),
        ]);
    }
    public static function execute(int $courseunitid, int $prerequisitecourseunitid): array {
        self::validate_parameters(self::execute_parameters(), compact('courseunitid', 'prerequisitecourseunitid'));
        $unit = academic_course_unit_helper::get($courseunitid);
        if (!$unit) {
            return academic_api_response::for_external(academic_api_response::fail_field('courseunitid', 'Course unit not found'));
        }
        academic_auth_helper::require_course_unit_write((int)$unit['courseid']);
        $result = course_unit_prerequisite_helper::remove($courseunitid, $prerequisitecourseunitid);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(course_unit_prerequisite_helper::structure_keys());
    }
}
