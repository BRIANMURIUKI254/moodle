<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\staff_messaging_helper;

class assert_staff_can_message extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'touserid' => new external_value(PARAM_INT, 'Recipient Moodle user id'),
        ]);
    }

    public static function execute(int $touserid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact('touserid'));
        require_login();
        $allowed = staff_messaging_helper::can_message((int)$USER->id, (int)$params['touserid']);
        if (!$allowed) {
            return academic_api_response::for_external(academic_api_response::fail_field(
                'touserid',
                'You are not permitted to message this user'
            ));
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'touserid' => (int)$params['touserid'],
            'allowed' => 1,
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'touserid' => new external_value(PARAM_INT, 'touserid'),
            'allowed' => new external_value(PARAM_INT, 'allowed'),
        ]);
    }
}
