<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\programme_container_helper;

class set_programme_container_course extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'courseid' => new external_value(PARAM_INT, 'Moodle container course id'),
        ]);
    }

    public static function execute(int $programmeid, int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'programmeid' => $programmeid,
            'courseid' => $courseid,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = programme_container_helper::set_container_courseid(
            (int)$params['programmeid'],
            (int)$params['courseid'],
            (int)($GLOBALS['USER']->id ?? 0)
        );
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'programmeid' => (int)$result['programmeid'],
            'courseid' => (int)$result['courseid'],
            'message' => (string)$result['message'],
        ]));
    }

    public static function execute_returns(): external_single_structure {
        return academic_api_response::returns_structure([
            'programmeid' => new external_value(PARAM_INT, 'programmeid'),
            'courseid' => new external_value(PARAM_INT, 'courseid'),
            'message' => new external_value(PARAM_TEXT, 'message'),
        ]);
    }
}
