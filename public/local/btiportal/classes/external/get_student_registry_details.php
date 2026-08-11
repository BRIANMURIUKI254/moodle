<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\registry_profile_helper;

/**
 * Read registrar personal details from Moodle custom profile fields.
 */
class get_student_registry_details extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);

        $context = \context_system::instance();
        self::validate_context($context);
        $usercontext = \context_user::instance((int)$params['userid']);
        if (!has_capability('local/btiportal:manageacademic', $context)
            && !has_capability('local/btiportal:viewacademic', $context)
            && !has_capability('moodle/user:viewdetails', $usercontext)
            && !has_capability('moodle/user:viewdetails', $context)) {
            throw new \required_capability_exception(
                $context,
                'local/btiportal:viewacademic',
                'nopermissions',
                ''
            );
        }

        $saved = registry_profile_helper::get_for_user((int)$params['userid']);
        $saved['userid'] = (int)$params['userid'];
        return academic_api_response::for_external(academic_api_response::ok($saved));
    }

    public static function execute_returns() {
        $keys = registry_profile_helper::structure_keys();
        $keys['userid'] = new external_value(PARAM_INT, 'userid');
        return academic_api_response::returns_structure($keys);
    }
}
