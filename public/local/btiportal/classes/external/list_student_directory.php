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
use local_btiportal\student_profile_helper;

class list_student_directory extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Optional profile status filter', VALUE_DEFAULT, ''),
            'academicstatus' => new external_value(PARAM_ALPHANUMEXT, 'Optional academic status filter', VALUE_DEFAULT, ''),
            'programmeid' => new external_value(PARAM_INT, 'Optional programme filter', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $status = '', string $academicstatus = '', int $programmeid = 0): array {
        self::validate_parameters(self::execute_parameters(), [
            'status' => $status,
            'academicstatus' => $academicstatus,
            'programmeid' => $programmeid,
        ]);
        academic_auth_helper::require_view_academic();
        $items = student_profile_helper::list_directory(
            $status !== '' ? $status : null,
            $academicstatus !== '' ? $academicstatus : null,
            $programmeid > 0 ? $programmeid : null
        );
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(student_profile_helper::directory_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
