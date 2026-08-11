<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\identity_helper;

/**
 * Explicitly mark a Moodle user for forced password change (first login).
 * Complements core_user_create_users createpassword=1.
 */
class ensure_force_password_change extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);

        identity_helper::ensure_force_password_change($userid);

        return [
            'userid' => $userid,
            'forcepasswordchange' => true,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User id'),
            'forcepasswordchange' => new external_value(PARAM_BOOL, 'Flag set'),
        ]);
    }
}
