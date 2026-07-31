<?php
/**
 * Return the site password policy as text for the portal UI.
 */
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class get_password_policy extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        global $CFG;
        return [
            'enabled' => !empty($CFG->passwordpolicy),
            'summary' => change_own_password::policy_summary(),
            'minlength' => (int)($CFG->minpasswordlength ?? 0),
            'digits' => (int)($CFG->minpassworddigits ?? 0),
            'lower' => (int)($CFG->minpasswordlower ?? 0),
            'upper' => (int)($CFG->minpasswordupper ?? 0),
            'special' => (int)($CFG->minpasswordnonalphanum ?? 0),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Whether password policy is enabled'),
            'summary' => new external_value(PARAM_TEXT, 'Human-readable policy summary'),
            'minlength' => new external_value(PARAM_INT, 'Minimum length'),
            'digits' => new external_value(PARAM_INT, 'Minimum digits'),
            'lower' => new external_value(PARAM_INT, 'Minimum lowercase letters'),
            'upper' => new external_value(PARAM_INT, 'Minimum uppercase letters'),
            'special' => new external_value(PARAM_INT, 'Minimum special characters'),
        ]);
    }
}
