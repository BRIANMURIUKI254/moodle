<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class get_current_user_context extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB, $USER;
        self::validate_parameters(self::execute_parameters(), []);
        $roles = $DB->get_fieldset_sql(
            'SELECT DISTINCT r.shortname FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid WHERE ra.userid = ?',
            [$USER->id]
        );
        $forcepasswordchange = (bool) get_user_preferences('auth_forcepasswordchange', false, $USER);
        return [
            'roles' => array_values($roles),
            'issiteadmin' => is_siteadmin($USER),
            'email' => $USER->email,
            'forcepasswordchange' => $forcepasswordchange,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'roles' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Moodle role shortname')),
            'issiteadmin' => new external_value(PARAM_BOOL, 'Whether the current user is a site administrator'),
            'email' => new external_value(PARAM_EMAIL, 'User email address'),
            'forcepasswordchange' => new external_value(PARAM_BOOL, 'Whether the user must change password on next login'),
        ]);
    }
}
