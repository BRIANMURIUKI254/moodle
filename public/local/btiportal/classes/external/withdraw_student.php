<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_lifecycle_helper;

class withdraw_student extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'effectivedate' => new external_value(PARAM_INT, 'Effective withdrawal date'),
            'reason' => new external_value(PARAM_TEXT, 'Withdrawal reason'),
            'notes' => new external_value(PARAM_TEXT, 'Notes', VALUE_DEFAULT, ''),
            'enrolmentpolicy' => new external_value(PARAM_ALPHANUMEXT, 'Enrolment policy', VALUE_DEFAULT, 'end_active'),
            'courseidstounenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
            'courseidstoenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $userid,
        int $effectivedate,
        string $reason,
        string $notes = '',
        string $enrolmentpolicy = 'end_active',
        string $courseidstounenrol = '',
        string $courseidstoenrol = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'effectivedate' => $effectivedate,
            'reason' => $reason,
            'notes' => $notes,
            'enrolmentpolicy' => $enrolmentpolicy,
            'courseidstounenrol' => $courseidstounenrol,
            'courseidstoenrol' => $courseidstoenrol,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = student_lifecycle_helper::withdraw($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'moodleuserid' => $result['moodleuserid'],
            'profile' => $result['profile'],
            'history' => $result['history'],
            'previous' => $result['previous'],
            'enrolment' => $result['enrolment'],
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_lifecycle_helper::lifecycle_result_structure_keys());
    }
}
