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

class list_elective_groups extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'yearofstudy' => new external_value(PARAM_INT, 'Year of study filter', VALUE_DEFAULT, 0),
            'semestersequence' => new external_value(PARAM_INT, 'Semester sequence filter', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Group status filter', VALUE_DEFAULT, 'active'),
        ]);
    }

    public static function execute(
        int $curriculumid,
        int $yearofstudy = 0,
        int $semestersequence = 0,
        string $status = 'active'
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'curriculumid', 'yearofstudy', 'semestersequence', 'status'
        ));
        academic_auth_helper::require_view_academic();
        $items = elective_helper::list_groups(
            (int)$params['curriculumid'],
            (int)$params['yearofstudy'],
            (int)$params['semestersequence'],
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
                new external_single_structure(elective_helper::group_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
