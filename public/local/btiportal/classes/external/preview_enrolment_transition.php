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
use local_btiportal\enrolment_transition_helper;

class preview_enrolment_transition extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        academic_auth_helper::require_view_academic();
        $preview = enrolment_transition_helper::preview((int)$params['userid']);
        return academic_api_response::for_external(academic_api_response::ok($preview));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(enrolment_transition_helper::preview_structure_keys());
    }
}
