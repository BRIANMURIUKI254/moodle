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

/**
 * Student self-service elective selection for the signed-in user only.
 * Does not require manageacademic — identity is forced to $USER->id.
 */
class save_my_student_elective_selections extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'electivegroupid' => new external_value(PARAM_INT, 'Elective group id'),
            'courseunitids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course unit id'),
                'Selected course unit ids',
                VALUE_DEFAULT,
                []
            ),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id'),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Selection status', VALUE_DEFAULT, 'confirmed'),
        ]);
    }

    public static function execute(
        int $electivegroupid,
        array $courseunitids = [],
        int $academicyearid = 0,
        int $semesterid = 0,
        string $status = 'confirmed'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'electivegroupid' => $electivegroupid,
            'courseunitids' => $courseunitids,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'status' => $status,
        ]);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $ids = array_values(array_unique(array_map('intval', $params['courseunitids'])));
        $result = elective_helper::save_student_selections(
            $userid,
            (int)$params['electivegroupid'],
            $ids,
            (int)$params['academicyearid'],
            (int)$params['semesterid'],
            (int)$USER->id,
            (string)$params['status']
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'selections' => $result['selections'] ?? [],
            'validation' => $result['validation'] ?? [
                'ok' => true,
                'errors' => [],
                'selectedcount' => count($result['selections'] ?? []),
                'selectedcredits' => 0,
            ],
            'count' => count($result['selections'] ?? []),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'selections' => new external_multiple_structure(
                new external_single_structure(elective_helper::selection_structure_keys())
            ),
            'validation' => new external_single_structure([
                'ok' => new external_value(PARAM_BOOL, 'ok'),
                'errors' => new external_multiple_structure(
                    new external_single_structure([
                        'field' => new external_value(PARAM_TEXT, 'field'),
                        'message' => new external_value(PARAM_TEXT, 'message'),
                    ])
                ),
                'selectedcount' => new external_value(PARAM_INT, 'selectedcount'),
                'selectedcredits' => new external_value(PARAM_FLOAT, 'selectedcredits'),
            ], 'validation', VALUE_OPTIONAL),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
