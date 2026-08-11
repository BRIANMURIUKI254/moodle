<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\registry_profile_helper;

/**
 * Save registrar personal details into Moodle custom profile fields.
 */
class save_student_registry_details extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'bti_dob' => new external_value(PARAM_TEXT, 'Date of birth', VALUE_OPTIONAL),
            'bti_gender' => new external_value(PARAM_TEXT, 'Gender', VALUE_OPTIONAL),
            'bti_nationality' => new external_value(PARAM_TEXT, 'Nationality', VALUE_OPTIONAL),
            'bti_nationalid' => new external_value(PARAM_TEXT, 'National ID', VALUE_OPTIONAL),
            'bti_guardian_name' => new external_value(PARAM_TEXT, 'Guardian name', VALUE_OPTIONAL),
            'bti_guardian_phone' => new external_value(PARAM_TEXT, 'Guardian phone', VALUE_OPTIONAL),
            'bti_guardian_email' => new external_value(PARAM_TEXT, 'Guardian email', VALUE_OPTIONAL),
            'bti_emergency_contact' => new external_value(PARAM_TEXT, 'Emergency contact', VALUE_OPTIONAL),
        ]);
    }

    public static function execute(
        int $userid,
        ?string $bti_dob = null,
        ?string $bti_gender = null,
        ?string $bti_nationality = null,
        ?string $bti_nationalid = null,
        ?string $bti_guardian_name = null,
        ?string $bti_guardian_phone = null,
        ?string $bti_guardian_email = null,
        ?string $bti_emergency_contact = null
    ): array {
        $incoming = ['userid' => $userid];
        $optional = [
            'bti_dob' => $bti_dob,
            'bti_gender' => $bti_gender,
            'bti_nationality' => $bti_nationality,
            'bti_nationalid' => $bti_nationalid,
            'bti_guardian_name' => $bti_guardian_name,
            'bti_guardian_phone' => $bti_guardian_phone,
            'bti_guardian_email' => $bti_guardian_email,
            'bti_emergency_contact' => $bti_emergency_contact,
        ];
        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $incoming[$key] = $value;
            }
        }

        $params = self::validate_parameters(self::execute_parameters(), $incoming);

        $context = \context_system::instance();
        self::validate_context($context);
        $usercontext = \context_user::instance((int)$params['userid']);
        if (!has_capability('local/btiportal:manageacademic', $context)
            && !has_capability('moodle/user:update', $usercontext)
            && !has_capability('moodle/user:update', $context)) {
            throw new \required_capability_exception(
                $context,
                'local/btiportal:manageacademic',
                'nopermissions',
                ''
            );
        }

        $data = [];
        foreach (registry_profile_helper::field_shortnames() as $shortname) {
            if (array_key_exists($shortname, $params)) {
                $data[$shortname] = (string)$params[$shortname];
            }
        }

        registry_profile_helper::save_for_user((int)$params['userid'], $data);
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
