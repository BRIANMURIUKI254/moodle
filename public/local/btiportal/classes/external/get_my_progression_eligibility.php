<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\progression_eligibility_helper;

/** Self-scoped progression eligibility for the signed-in student. */
class get_my_progression_eligibility extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'actiontype' => new external_value(
                PARAM_ALPHANUMEXT,
                'semester_progression or promotion',
                VALUE_DEFAULT,
                'semester_progression'
            ),
        ]);
    }

    public static function execute(string $actiontype = 'semester_progression'): array {
        $params = self::validate_parameters(self::execute_parameters(), ['actiontype' => $actiontype]);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $result = progression_eligibility_helper::assess($userid, $params['actiontype']);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        $eligibility = progression_eligibility_helper::sanitize_for_external($result['eligibility']);
        return academic_api_response::for_external(academic_api_response::ok($eligibility));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(
            progression_eligibility_helper::eligibility_structure_keys()
        );
    }
}
