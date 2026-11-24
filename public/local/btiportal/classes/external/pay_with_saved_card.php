<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\fees_payment_helper;
use local_btiportal\fees_student_auth_helper;

class pay_with_saved_card extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cardid' => new external_value(PARAM_INT, 'Saved card id'),
            'academicyearid' => new external_value(PARAM_INT, 'Target year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Target semester', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $cardid, int $academicyearid = 0, int $semesterid = 0): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        fees_student_auth_helper::require_eligible_student_payer((int)$USER->id);
        $params = self::validate_parameters(self::execute_parameters(), [
            'cardid' => $cardid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ]);
        $result = fees_payment_helper::pay_with_saved_card(
            (int)$USER->id,
            (int)$params['cardid'],
            (int)$params['academicyearid'],
            (int)$params['semesterid']
        );
        if (!$result['ok']) {
            throw new \invalid_parameter_exception($result['errors'][0]['message'] ?? 'Payment failed');
        }
        return [
            'reference' => (string)($result['reference'] ?? ''),
            'paid' => !empty($result['paid']),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'reference' => new external_value(PARAM_TEXT, 'Payment reference'),
            'paid' => new external_value(PARAM_BOOL, 'Semester marked paid'),
        ]);
    }
}