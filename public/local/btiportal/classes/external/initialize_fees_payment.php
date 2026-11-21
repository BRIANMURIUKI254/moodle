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

class initialize_fees_payment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        fees_student_auth_helper::require_eligible_student_payer((int)$USER->id);

        $result = fees_payment_helper::initialize_for_user((int)$USER->id);
        if (!$result['ok']) {
            throw new \invalid_parameter_exception($result['errors'][0]['message'] ?? 'Could not start payment');
        }

        return [
            'reference' => $result['reference'],
            'authorization_url' => $result['authorization_url'],
            'amount' => (int)$result['amount'],
            'currency' => (string)$result['currency'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'reference' => new external_value(PARAM_TEXT, 'Payment reference'),
            'authorization_url' => new external_value(PARAM_URL, 'Paystack checkout URL'),
            'amount' => new external_value(PARAM_INT, 'Amount in minor currency units'),
            'currency' => new external_value(PARAM_ALPHA, 'ISO currency code'),
        ]);
    }
}