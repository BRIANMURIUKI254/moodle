<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\department_auth_helper;
use local_btiportal\operational_notification_helper;

class send_operational_notification extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'id'),
            'scheduleonly' => new external_value(PARAM_BOOL, 'scheduleonly', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $id, bool $scheduleonly = false): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact('id', 'scheduleonly'));
        department_auth_helper::require_operational_access(null, 'local/btiportal:managedepartmentnotifications');
        $result = operational_notification_helper::send($id, (int)$USER->id, $scheduleonly);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(operational_notification_helper::structure_keys());
    }
}
