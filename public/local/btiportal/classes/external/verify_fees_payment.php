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
use local_btiportal\student_fees_helper;

class verify_fees_payment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reference' => new external_value(PARAM_TEXT, 'Paystack payment reference'),
        ]);
    }

    public static function execute(string $reference): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        fees_student_auth_helper::require_eligible_student_payer((int)$USER->id);

        $params = self::validate_parameters(self::execute_parameters(), ['reference' => $reference]);
        $reference = trim($params['reference']);
        if ($reference === '') {
            throw new \invalid_parameter_exception('Reference is required');
        }

        global $DB;
        $attempt = $DB->get_record('local_btiportal_fees_payment_attempt', ['reference' => $reference], '*', IGNORE_MISSING);
        if (!$attempt || (int)$attempt->userid !== (int)$USER->id) {
            throw new \invalid_parameter_exception('Unknown payment reference');
        }

        $result = fees_payment_helper::verify_and_complete($reference, true);
        if (!$result['ok']) {
            throw new \invalid_parameter_exception($result['errors'][0]['message'] ?? 'Verification failed');
        }

        return [
            'verified' => !empty($result['verified']),
            'paid' => !empty($result['paid']) || student_fees_helper::is_paid((int)$USER->id),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'verified' => new external_value(PARAM_BOOL, 'Paystack reported success'),
            'paid' => new external_value(PARAM_BOOL, 'Student fees marked paid'),
        ]);
    }
}