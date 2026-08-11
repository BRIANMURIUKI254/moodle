<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_enrolment_helper;

class execute_student_academic_enrolment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'operationtype' => new external_value(PARAM_ALPHANUMEXT, 'Operation type', VALUE_DEFAULT, 'manual_reconciliation'),
            'courseunitids' => new external_value(PARAM_TEXT, 'Comma-separated course unit ids', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $userid,
        string $operationtype = 'manual_reconciliation',
        string $courseunitids = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'operationtype' => $operationtype,
            'courseunitids' => $courseunitids,
        ]);
        academic_auth_helper::require_manage_academic();
        $ids = [];
        if ($params['courseunitids'] !== '') {
            foreach (preg_split('/\s*,\s*/', $params['courseunitids']) as $part) {
                $id = (int)$part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $result = academic_enrolment_helper::execute(
            (int)$params['userid'],
            $params['operationtype'],
            $ids,
            (int)$USER->id
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail(
                $result['errors'] ?? [['field' => '', 'message' => 'Enrolment execution failed']]
            ));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['result']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_enrolment_helper::result_structure_keys());
    }
}
