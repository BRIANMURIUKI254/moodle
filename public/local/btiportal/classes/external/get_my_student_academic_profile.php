<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_portal_helper;
use local_btiportal\student_profile_helper;

/** Self-scoped enriched academic profile for the signed-in student. */
class get_my_student_academic_profile extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $row = student_profile_helper::get_by_userid($userid);
        if (!$row) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('userid', 'Student academic profile not found')
            );
        }
        $enriched = student_portal_helper::enrich_profile($row);
        return academic_api_response::for_external(academic_api_response::ok($enriched));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(
            student_portal_helper::enriched_profile_structure_keys()
        );
    }
}
