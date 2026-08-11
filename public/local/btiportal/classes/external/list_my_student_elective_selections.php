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

/** Self-scoped elective selections for the signed-in student. */
class list_my_student_elective_selections extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Selection status', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $academicyearid = 0, int $semesterid = 0, string $status = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'status' => $status,
        ]);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $items = elective_helper::list_student_selections(
            $userid,
            (int)$params['academicyearid'],
            (int)$params['semesterid'],
            (string)$params['status']
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
