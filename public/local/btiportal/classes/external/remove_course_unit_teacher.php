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

class remove_course_unit_teacher extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Assignment id'),
        ]);
    }
    public static function execute(int $id): array {
        self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $row = course_unit_teacher_helper::get($id);
        if (!$row) {
            return academic_api_response::for_external(academic_api_response::fail_field('id', 'Teacher assignment not found'));
        }
        $unit = academic_course_unit_helper::get((int)$row['courseunitid']);
        if (!$unit) {
            return academic_api_response::for_external(academic_api_response::fail_field('courseunitid', 'Course unit not found'));
        }
        academic_auth_helper::require_course_unit_write((int)$unit['courseid']);
        $result = course_unit_teacher_helper::remove($id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(course_unit_teacher_helper::structure_keys());
    }
}
