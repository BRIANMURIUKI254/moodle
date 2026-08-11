<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\progression_eligibility_helper;

class get_progression_eligibility extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'actiontype' => new external_value(PARAM_ALPHANUMEXT, 'semester_progression or promotion'),
        ]);
    }

    public static function execute(int $userid, string $actiontype): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'actiontype' => $actiontype,
        ]);
        academic_auth_helper::require_view_academic();
        $result = progression_eligibility_helper::assess(
            (int)$params['userid'],
            $params['actiontype']
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['eligibility']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(
            progression_eligibility_helper::eligibility_structure_keys()
        );
    }
}
