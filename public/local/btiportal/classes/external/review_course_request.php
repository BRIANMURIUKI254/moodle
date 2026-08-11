<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_course_request_helper;

class review_course_request extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Request id'),
            'decision' => new external_value(PARAM_ALPHA, 'approve or reject'),
            'adminnotes' => new external_value(PARAM_TEXT, 'Admin notes', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $id, string $decision, string $adminnotes = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'decision' => $decision,
            'adminnotes' => $adminnotes,
        ]);
        academic_auth_helper::require_manage_academic();
        $decision = strtolower((string)$params['decision']);
        if ($decision === 'approve') {
            $result = student_course_request_helper::approve((int)$params['id'], (int)$USER->id, (string)$params['adminnotes']);
        } else if ($decision === 'reject') {
            $result = student_course_request_helper::reject((int)$params['id'], (int)$USER->id, (string)$params['adminnotes']);
        } else {
            return academic_api_response::for_external(
                academic_api_response::fail_field('decision', 'Decision must be approve or reject')
            );
        }
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_course_request_helper::structure_keys());
    }
}
