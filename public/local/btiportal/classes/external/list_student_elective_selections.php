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
use local_btiportal\elective_helper;

class list_student_elective_selections extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Selection status filter', VALUE_DEFAULT, 'confirmed'),
        ]);
    }

    public static function execute(
        int $userid,
        int $academicyearid = 0,
        int $semesterid = 0,
        string $status = 'confirmed'
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'academicyearid', 'semesterid', 'status'
        ));
        academic_auth_helper::require_view_academic();
        $items = elective_helper::list_student_selections(
            (int)$params['userid'],
            (int)$params['academicyearid'],
            (int)$params['semesterid'],
            $params['status']
        );
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(elective_helper::selection_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
