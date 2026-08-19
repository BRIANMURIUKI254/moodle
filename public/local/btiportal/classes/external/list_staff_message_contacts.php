<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\staff_messaging_helper;

class list_staff_message_contacts extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search query', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $query = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact('query'));
        require_login();
        $items = staff_messaging_helper::list_contacts((int)$USER->id, (string)$params['query']);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(staff_messaging_helper::contact_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
