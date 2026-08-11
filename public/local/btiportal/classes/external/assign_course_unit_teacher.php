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

class assign_course_unit_teacher extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseunitid' => new external_value(PARAM_INT, 'Course unit id'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'teachingrole' => new external_value(PARAM_ALPHANUMEXT, 'Teaching role'),
            'isprimary' => new external_value(PARAM_BOOL, 'Primary flag', VALUE_DEFAULT, false),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id', VALUE_DEFAULT, 0),
        ]);
    }
    public static function execute(
        int $courseunitid, int $userid, string $teachingrole,
        bool $isprimary = false, string $status = 'active',
        int $academicyearid = 0, int $semesterid = 0
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'courseunitid', 'userid', 'teachingrole', 'isprimary', 'status',
            'academicyearid', 'semesterid'
        ));
        $unit = academic_course_unit_helper::get($courseunitid);
        if (!$unit) {
            return academic_api_response::for_external(academic_api_response::fail_field('courseunitid', 'Course unit not found'));
        }
        if ((int)$unit['courseid'] > 1) {
            academic_auth_helper::require_course_unit_write((int)$unit['courseid']);
        } else {
            academic_auth_helper::require_manage_academic();
        }
        $result = course_unit_teacher_helper::assign($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(course_unit_teacher_helper::structure_keys());
    }
}
