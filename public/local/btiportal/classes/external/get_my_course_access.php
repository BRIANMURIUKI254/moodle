<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_course_access_helper;

class get_my_course_access extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
        ]);
    }

    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('courseid'));
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $access = student_course_access_helper::course_access($userid, (int)$params['courseid']);
        return academic_api_response::for_external(academic_api_response::ok($access));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_course_access_helper::item_structure_keys());
    }
}
