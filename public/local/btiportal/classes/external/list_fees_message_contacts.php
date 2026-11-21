<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\fees_messaging_helper;

class list_fees_message_contacts extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $query = ''): array {
        academic_auth_helper::require_student_self_read();
        return ['contacts' => fees_messaging_helper::list_contacts($query)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'contacts' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                ])
            ),
        ]);
    }
}