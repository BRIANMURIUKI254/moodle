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
use local_btiportal\lecturer_teaching_helper;

class list_lecturer_course_units extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Lecturer Moodle user id'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Assignment status filter', VALUE_DEFAULT, 'active'),
        ]);
    }

    public static function execute(int $userid, string $status = 'active'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'status' => $status,
        ]);
        academic_auth_helper::require_view_lecturer_record((int)$params['userid']);
        $items = lecturer_teaching_helper::list_course_units((int)$params['userid'], $params['status']);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(lecturer_teaching_helper::course_unit_assignment_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
