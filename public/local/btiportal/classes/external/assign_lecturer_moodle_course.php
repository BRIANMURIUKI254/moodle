<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\lecturer_teaching_helper;

class assign_lecturer_moodle_course extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Lecturer Moodle user id'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'role' => new external_value(PARAM_ALPHANUMEXT, 'editingteacher or teacher', VALUE_DEFAULT, 'editingteacher'),
        ]);
    }

    public static function execute(int $userid, int $courseid, string $role = 'editingteacher'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseid' => $courseid,
            'role' => $role,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = lecturer_teaching_helper::assign_moodle_course(
            (int)$params['userid'],
            (int)$params['courseid'],
            $params['role']
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(lecturer_teaching_helper::moodle_teaching_structure_keys());
    }
}
