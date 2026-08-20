<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\student_account_helper;

/**
 * Create a Moodle student user with an atomic admission number and no core welcome email.
 */
class create_student_account extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'firstname' => new external_value(PARAM_NOTAGS, 'First name'),
            'lastname' => new external_value(PARAM_NOTAGS, 'Last name'),
            'email' => new external_value(PARAM_RAW_TRIMMED, 'Email address'),
            'middlename' => new external_value(PARAM_NOTAGS, 'Middle name', VALUE_DEFAULT, ''),
            'phone1' => new external_value(PARAM_TEXT, 'Phone', VALUE_DEFAULT, ''),
            'address' => new external_value(PARAM_TEXT, 'Postal address', VALUE_DEFAULT, ''),
            'city' => new external_value(PARAM_TEXT, 'City', VALUE_DEFAULT, ''),
            'country' => new external_value(PARAM_TEXT, 'Country code or name', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_TEXT, 'Department label', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        string $firstname,
        string $lastname,
        string $email,
        string $middlename = '',
        string $phone1 = '',
        string $address = '',
        string $city = '',
        string $country = '',
        string $department = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'middlename' => $middlename,
            'phone1' => $phone1,
            'address' => $address,
            'city' => $city,
            'country' => $country,
            'department' => $department,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);

        return student_account_helper::create($params);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Moodle user id'),
            'username' => new external_value(PARAM_RAW, 'Assigned username'),
            'admissionnumber' => new external_value(PARAM_TEXT, 'Admission number stored as idnumber'),
        ]);
    }
}
