<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_course_request_helper;

class create_my_course_request extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requesttype' => new external_value(PARAM_ALPHANUMEXT, 'deferral or programme_change'),
            'reason' => new external_value(PARAM_TEXT, 'Reason'),
            'targetprogrammeid' => new external_value(PARAM_INT, 'Target programme', VALUE_DEFAULT, 0),
            'paymentconfirmed' => new external_value(PARAM_BOOL, 'Student confirms payment/contact', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        string $requesttype,
        string $reason,
        int $targetprogrammeid = 0,
        bool $paymentconfirmed = false
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'requesttype' => $requesttype,
            'reason' => $reason,
            'targetprogrammeid' => $targetprogrammeid,
            'paymentconfirmed' => $paymentconfirmed,
        ]);
        academic_auth_helper::require_student_self_read();
        $result = student_course_request_helper::create_request((int)$USER->id, $params);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_course_request_helper::structure_keys());
    }
}
