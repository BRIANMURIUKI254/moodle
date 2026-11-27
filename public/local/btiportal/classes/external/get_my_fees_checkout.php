<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\fees_payment_helper;
use local_btiportal\fees_student_auth_helper;

class get_my_fees_checkout extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        fees_student_auth_helper::require_eligible_student_payer((int)$USER->id);
        $data = fees_payment_helper::checkout_context_for_user((int)$USER->id);
        if (!isset($data['default_card_id']) || $data['default_card_id'] === null) {
            unset($data['default_card_id']);
        } else {
            $data['default_card_id'] = (int) $data['default_card_id'];
        }
        if (empty($data['current_period']) || !is_array($data['current_period'])) {
            unset($data['current_period']);
        }
        $data['paystack_public_key'] = (string) ($data['paystack_public_key'] ?? '');
        foreach ($data['lines'] as $i => $line) {
            if (!isset($line['due_at']) || $line['due_at'] === null) {
                unset($data['lines'][$i]['due_at']);
            }
        }
        return $data;
    }

    public static function execute_returns(): external_single_structure {
        $line = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Line id'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'amount' => new external_value(PARAM_INT, 'Minor units'),
            'currency' => new external_value(PARAM_ALPHA, 'Currency'),
            'status' => new external_value(PARAM_ALPHA, 'published'),
            'due_at' => new external_value(PARAM_INT, 'Due unix', VALUE_OPTIONAL),
        ]);
        $card = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Card id'),
            'brand' => new external_value(PARAM_TEXT, 'Brand'),
            'last4' => new external_value(PARAM_TEXT, 'Last four'),
            'exp_month' => new external_value(PARAM_INT, 'Expiry month'),
            'exp_year' => new external_value(PARAM_INT, 'Expiry year'),
            'reusable' => new external_value(PARAM_BOOL, 'Reusable'),
            'is_default' => new external_value(PARAM_BOOL, 'Default card'),
        ]);
        $bill = new external_single_structure([
            'academicyearid' => new external_value(PARAM_INT, 'Year id'),
            'semesterid' => new external_value(PARAM_INT, 'Semester id'),
            'academic_year' => new external_value(PARAM_TEXT, 'Year label'),
            'semester' => new external_value(PARAM_TEXT, 'Semester label'),
            'amount' => new external_value(PARAM_INT, 'Minor units'),
            'currency' => new external_value(PARAM_ALPHA, 'Currency'),
            'due_at' => new external_value(PARAM_INT, 'Due unix'),
            'status' => new external_value(PARAM_ALPHA, 'Paid|Unpaid|Overdue'),
            'is_current' => new external_value(PARAM_BOOL, 'Current semester bill'),
            'can_pay_online' => new external_value(PARAM_BOOL, 'Pay enabled'),
            'pay_disabled_reason' => new external_value(PARAM_TEXT, 'Pay disabled reason', VALUE_DEFAULT, ''),
        ]);
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'unpaid|paid'),
            'amount' => new external_value(PARAM_INT, 'Current semester minor units'),
            'currency' => new external_value(PARAM_ALPHA, 'Currency'),
            'due_at' => new external_value(PARAM_INT, 'Due unix', VALUE_DEFAULT, 0),
            'published' => new external_value(PARAM_BOOL, 'Current semester published'),
            'lines' => new external_multiple_structure($line, 'Course breakdown'),
            'cards' => new external_multiple_structure($card, 'Saved cards'),
            'default_card_id' => new external_value(PARAM_INT, 'Default card id', VALUE_OPTIONAL),
            'bills' => new external_multiple_structure($bill, 'All semester bills'),
            'total_outstanding' => new external_value(PARAM_INT, 'Outstanding minor units'),
            'online_payments_available' => new external_value(PARAM_BOOL, 'Paystack configured'),
            'paystack_public_key' => new external_value(PARAM_TEXT, 'Paystack public key for inline checkout', VALUE_DEFAULT, ''),
            'paystack_inline_ready' => new external_value(PARAM_BOOL, 'Popup checkout ready'),
            'first_time_payer' => new external_value(PARAM_BOOL, 'No prior semester tuition paid'),
            'current_period' => new external_single_structure([
                'academicyearid' => new external_value(PARAM_INT, 'Profile academic year id'),
                'semesterid' => new external_value(PARAM_INT, 'Profile semester id'),
            ], 'Student profile period', VALUE_OPTIONAL),
        ]);
    }
}