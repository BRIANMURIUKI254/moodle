<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_enrolment_helper;

class preview_student_academic_enrolment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'operationtype' => new external_value(PARAM_ALPHANUMEXT, 'Operation type', VALUE_DEFAULT, 'manual_reconciliation'),
        ]);
    }

    public static function execute(int $userid, string $operationtype = 'manual_reconciliation'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'operationtype' => $operationtype,
        ]);
        academic_auth_helper::require_view_academic();
        $result = academic_enrolment_helper::preview((int)$params['userid'], $params['operationtype']);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['preview']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_enrolment_helper::preview_structure_keys());
    }
}
