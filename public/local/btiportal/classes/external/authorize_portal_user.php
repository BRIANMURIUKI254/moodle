<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\service_auth_helper;

/**
 * Authorise a user for the bti_portal_api service (Authorised users list).
 */
class authorize_portal_user extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id to authorise for bti_portal_api'),
        ]);
    }

    public static function execute(int $userid): array {
        self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);

        return service_auth_helper::ensure_portal_authorised($userid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Authorised user id'),
            'serviceid' => new external_value(PARAM_INT, 'External service id'),
            'shortname' => new external_value(PARAM_ALPHANUMEXT, 'Service shortname'),
            'authorised' => new external_value(PARAM_BOOL, 'True if the user may use the service'),
            'created' => new external_value(PARAM_BOOL, 'True if a new Authorised users row was inserted'),
            'restricted' => new external_value(PARAM_BOOL, 'True if the service uses restricted users'),
        ]);
    }
}
