<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_enrolment_helper;
use local_btiportal\enrolment_reconciliation_helper;

class execute_enrolment_reconciliation extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'operationtype' => new external_value(PARAM_ALPHANUMEXT, 'Operation type', VALUE_DEFAULT, 'manual_reconciliation'),
            'courseunitids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course unit id'),
                'Course unit ids to enrol',
                VALUE_DEFAULT,
                []
            ),
            'policy' => new external_value(
                PARAM_ALPHANUMEXT,
                'Delivery transition policy',
                VALUE_DEFAULT,
                'preserve_and_enrol_new'
            ),
            'endcourseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Moodle course id'),
                'Moodle course ids to end/suspend',
                VALUE_DEFAULT,
                []
            ),
            'unenrolcourseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Moodle course id'),
                'Moodle course ids to hard-unenrol',
                VALUE_DEFAULT,
                []
            ),
            'confirmdestructive' => new external_value(PARAM_INT, 'Confirm destructive actions 0/1', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $userid,
        string $operationtype = 'manual_reconciliation',
        array $courseunitids = [],
        string $policy = 'preserve_and_enrol_new',
        array $endcourseids = [],
        array $unenrolcourseids = [],
        int $confirmdestructive = 0
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'operationtype' => $operationtype,
            'courseunitids' => $courseunitids,
            'policy' => $policy,
            'endcourseids' => $endcourseids,
            'unenrolcourseids' => $unenrolcourseids,
            'confirmdestructive' => $confirmdestructive,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = enrolment_reconciliation_helper::execute(
            (int)$params['userid'],
            $params['operationtype'],
            array_values(array_unique(array_map('intval', $params['courseunitids']))),
            (int)$USER->id,
            $params['policy'],
            array_values(array_unique(array_map('intval', $params['endcourseids']))),
            array_values(array_unique(array_map('intval', $params['unenrolcourseids']))),
            (int)$params['confirmdestructive'] === 1
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail(
                $result['errors'] ?? [['field' => '', 'message' => 'Enrolment reconciliation failed']]
            ));
        }
        $payload = $result['result'];
        // Shape to academic_enrolment_helper result keys for WS encoding.
        $out = [
            'userid' => (int)$params['userid'],
            'moodleuserid' => (int)$params['userid'],
            'operationtype' => $params['operationtype'],
            'status' => (string)($payload['status'] ?? 'completed'),
            'enrolled' => $payload['enrolled'] ?? [],
            'alreadyenrolled' => $payload['alreadyenrolled'] ?? [],
            'skipped' => $payload['skipped'] ?? [],
            'manualreview' => $payload['manualreview'] ?? [],
            'failed' => $payload['failed'] ?? [],
            'gradespreserved' => (int)($payload['identitypreserved']['gradespreserved'] ?? 1),
            'historypreserved' => (int)($payload['identitypreserved']['historypreserved'] ?? 1),
            'userdeleted' => (int)($payload['identitypreserved']['userdeleted'] ?? 0),
        ];
        if (!empty($payload['operation'])) {
            $out['operation'] = $payload['operation'];
        }
        if (!empty($payload['plan'])) {
            $out['plan'] = $payload['plan'];
        }
        return academic_api_response::for_external(academic_api_response::ok($out));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_enrolment_helper::result_structure_keys());
    }
}
