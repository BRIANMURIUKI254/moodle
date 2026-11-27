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
        return new external_function_parameters([
            'save_card' => new external_value(PARAM_BOOL, 'Store reusable authorization after success', VALUE_DEFAULT, false),
            'academicyearid' => new external_value(PARAM_INT, 'Target academic year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Target semester', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(bool $save_card = false, int $academicyearid = 0, int $semesterid = 0): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        fees_student_auth_helper::require_eligible_student_payer((int)$USER->id);
        $params = self::validate_parameters(self::execute_parameters(), [
            'save_card' => $save_card,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ]);

        $result = fees_payment_helper::initialize_for_user(
            (int)$USER->id,
            !empty($params['save_card']),
            (int)$params['academicyearid'],
            (int)$params['semesterid']
        );
        if (!$result['ok']) {
            throw new \invalid_parameter_exception($result['errors'][0]['message'] ?? 'Could not start payment');
        }

        $response = [
            'reference' => $result['reference'],
            'authorization_url' => $result['authorization_url'],
            'access_code' => (string)($result['access_code'] ?? ''),
            'amount' => (int)$result['amount'],
            'currency' => (string)$result['currency'],
        ];
        if (!empty($result['paystack_public_key'])) {
            $response['paystack_public_key'] = (string)$result['paystack_public_key'];
        }
        if (!empty($result['paystack_channels']) && is_array($result['paystack_channels'])) {
            $response['paystack_channels'] = $result['paystack_channels'];
        }
        return $response;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'reference' => new external_value(PARAM_TEXT, 'Payment reference'),
            'authorization_url' => new external_value(PARAM_URL, 'Paystack checkout URL'),
            'access_code' => new external_value(PARAM_TEXT, 'Paystack access_code for inline popup'),
            'amount' => new external_value(PARAM_INT, 'Amount in minor currency units'),
            'currency' => new external_value(PARAM_ALPHA, 'ISO currency code'),
            'paystack_public_key' => new external_value(PARAM_TEXT, 'Matched public key for inline popup', VALUE_OPTIONAL),
            'paystack_channels' => new \core_external\external_multiple_structure(
                new external_value(PARAM_ALPHA, 'Paystack channel'),
                'Checkout payment channels',
                VALUE_OPTIONAL
            ),
        ]);
    }
}