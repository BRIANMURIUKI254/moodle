<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_course_access_helper;

class ensure_my_pathway_enrolments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $result = student_course_access_helper::ensure_pathway_enrolments($userid);
        return academic_api_response::for_external(academic_api_response::ok($result));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_course_access_helper::ensure_structure_keys());
    }
}
