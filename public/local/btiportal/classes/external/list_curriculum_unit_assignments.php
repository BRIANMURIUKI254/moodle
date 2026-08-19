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
use local_btiportal\curriculum_unit_helper;

class list_curriculum_unit_assignments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
        ]);
    }

    public static function execute(int $curriculumid): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('curriculumid'));
        academic_auth_helper::require_view_academic();
        $items = curriculum_unit_helper::list_assignments((int)$params['curriculumid']);
        return academic_api_response::for_external(academic_api_response::ok([
            'curriculumid' => (int)$params['curriculumid'],
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'items' => new external_multiple_structure(
                new external_single_structure(curriculum_unit_helper::assignment_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
