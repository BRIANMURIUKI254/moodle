<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_card_helper;

class remove_my_card extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cardid' => new external_value(PARAM_INT, 'Saved card id'),
        ]);
    }

    public static function execute(int $cardid): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        $params = self::validate_parameters(self::execute_parameters(), ['cardid' => $cardid]);
        $removed = student_card_helper::remove((int)$params['cardid'], (int)$USER->id);
        if (!$removed) {
            throw new \invalid_parameter_exception('Card not found');
        }
        return ['removed' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'removed' => new external_value(PARAM_BOOL, 'Removed'),
        ]);
    }
}