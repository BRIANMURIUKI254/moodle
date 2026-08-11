<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\department_auth_helper;
use local_btiportal\operational_notification_helper;

class create_operational_notification extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'title' => new external_value(PARAM_TEXT, 'title'),
            'body' => new external_value(PARAM_RAW, 'body'),
            'scope' => new external_value(PARAM_ALPHANUMEXT, 'scope', VALUE_DEFAULT, 'department'),
            'departmentid' => new external_value(PARAM_INT, 'departmentid', VALUE_DEFAULT, 0),
            'schoolid' => new external_value(PARAM_INT, 'schoolid', VALUE_DEFAULT, 0),
            'programmeid' => new external_value(PARAM_INT, 'programmeid', VALUE_DEFAULT, 0),
            'courseunitid' => new external_value(PARAM_INT, 'courseunitid', VALUE_DEFAULT, 0),
            'moodlecourseid' => new external_value(PARAM_INT, 'moodlecourseid', VALUE_DEFAULT, 0),
            'targetjson' => new external_value(PARAM_RAW, 'targetjson', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status', VALUE_DEFAULT, 'draft'),
            'timescheduled' => new external_value(PARAM_INT, 'timescheduled', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        string $title,
        string $body,
        string $scope = 'department',
        int $departmentid = 0,
        int $schoolid = 0,
        int $programmeid = 0,
        int $courseunitid = 0,
        int $moodlecourseid = 0,
        string $targetjson = '',
        string $status = 'draft',
        int $timescheduled = 0
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'title', 'body', 'scope', 'departmentid', 'schoolid', 'programmeid',
            'courseunitid', 'moodlecourseid', 'targetjson', 'status', 'timescheduled'
        ));
        department_auth_helper::require_operational_access(
            $departmentid > 0 ? $departmentid : null,
            'local/btiportal:managedepartmentnotifications',
            !department_auth_helper::is_university_admin((int)$USER->id)
        );
        $result = operational_notification_helper::create($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(operational_notification_helper::structure_keys());
    }
}
