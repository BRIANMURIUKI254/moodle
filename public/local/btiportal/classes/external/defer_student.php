<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_lifecycle_helper;

class defer_student extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'effectivedate' => new external_value(PARAM_INT, 'Effective deferral date'),
            'expectedreturndate' => new external_value(PARAM_INT, 'Expected return date'),
            'reason' => new external_value(PARAM_TEXT, 'Deferral reason'),
            'notes' => new external_value(PARAM_TEXT, 'Optional notes', VALUE_DEFAULT, ''),
            'enrolmentpolicy' => new external_value(PARAM_ALPHANUMEXT, 'Enrolment policy', VALUE_DEFAULT, 'preserve'),
            'courseidstounenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
            'courseidstoenrol' => new external_value(PARAM_TEXT, 'Comma-separated course ids', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $userid,
        int $effectivedate,
        int $expectedreturndate,
        string $reason,
        string $notes = '',
        string $enrolmentpolicy = 'preserve',
        string $courseidstounenrol = '',
        string $courseidstoenrol = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'effectivedate' => $effectivedate,
            'expectedreturndate' => $expectedreturndate,
            'reason' => $reason,
            'notes' => $notes,
            'enrolmentpolicy' => $enrolmentpolicy,
            'courseidstounenrol' => $courseidstounenrol,
            'courseidstoenrol' => $courseidstoenrol,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = student_lifecycle_helper::defer($params, (int)$USER->id);
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
