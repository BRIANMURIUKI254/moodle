<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_profile_helper;

class get_student_academic_profile extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }
    public static function execute(int $userid): array {
        self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        academic_auth_helper::require_view_academic();
        $row = student_profile_helper::get_by_userid($userid);
        if (!$row) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('userid', 'Student academic profile not found')
            );
        }
        return academic_api_response::for_external(academic_api_response::ok($row));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(student_profile_helper::structure_keys());
    }
}
