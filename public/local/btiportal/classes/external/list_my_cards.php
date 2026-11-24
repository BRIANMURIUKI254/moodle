<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_card_helper;

class list_my_cards extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        return ['cards' => student_card_helper::list_for_user((int)$USER->id)];
    }

    public static function execute_returns(): external_single_structure {
        $card = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Card id'),
            'brand' => new external_value(PARAM_TEXT, 'Brand'),
            'last4' => new external_value(PARAM_TEXT, 'Last four'),
            'exp_month' => new external_value(PARAM_INT, 'Expiry month'),
            'exp_year' => new external_value(PARAM_INT, 'Expiry year'),
            'reusable' => new external_value(PARAM_BOOL, 'Reusable'),
            'is_default' => new external_value(PARAM_BOOL, 'Default'),
        ]);
        return new external_single_structure([
            'cards' => new external_multiple_structure($card),
        ]);
    }
}