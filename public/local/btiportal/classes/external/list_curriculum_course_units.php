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
use local_btiportal\curriculum_delivery_helper;

class list_curriculum_course_units extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'yearnumber' => new external_value(PARAM_INT, 'Curriculum year number'),
            'semesternumber' => new external_value(PARAM_INT, 'Curriculum semester number'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Unit status filter', VALUE_DEFAULT, 'active'),
        ]);
    }

    public static function execute(
        int $curriculumid,
        int $yearnumber,
        int $semesternumber,
        string $status = 'active'
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'curriculumid', 'yearnumber', 'semesternumber', 'status'
        ));
        academic_auth_helper::require_view_academic();
        $items = curriculum_delivery_helper::list_course_units_for_semester(
            (int)$params['curriculumid'],
            (int)$params['yearnumber'],
            (int)$params['semesternumber'],
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
                new external_single_structure(curriculum_delivery_helper::enriched_unit_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
