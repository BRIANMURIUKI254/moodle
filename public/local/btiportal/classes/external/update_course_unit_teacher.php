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

class update_course_unit_teacher extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Assignment id'),
            'teachingrole' => new external_value(PARAM_ALPHANUMEXT, 'Role', VALUE_DEFAULT, ''),
            'isprimary' => new external_value(PARAM_INT, '0/1/-1', VALUE_DEFAULT, -1),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(int $id, string $teachingrole = '', int $isprimary = -1, string $status = ''): array {
        self::validate_parameters(self::execute_parameters(), compact('id', 'teachingrole', 'isprimary', 'status'));
        $row = course_unit_teacher_helper::get($id);
        if (!$row) {
            return academic_api_response::for_external(academic_api_response::fail_field('id', 'Teacher assignment not found'));
        }
        $unit = academic_course_unit_helper::get((int)$row['courseunitid']);
        if (!$unit) {
            return academic_api_response::for_external(academic_api_response::fail_field('courseunitid', 'Course unit not found'));
        }
        academic_auth_helper::require_course_unit_write((int)$unit['courseid']);
        $input = [];
        if ($teachingrole !== '') { $input['teachingrole'] = $teachingrole; }
        if ($isprimary === 0 || $isprimary === 1) { $input['isprimary'] = $isprimary; }
        if ($status !== '') { $input['status'] = $status; }
        $result = course_unit_teacher_helper::update($id, $input);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(course_unit_teacher_helper::structure_keys());
    }
}
