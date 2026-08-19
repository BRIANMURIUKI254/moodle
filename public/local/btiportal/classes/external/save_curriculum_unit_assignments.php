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

class save_curriculum_unit_assignments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'units' => new external_multiple_structure(
                new external_single_structure([
                    'courseunitid' => new external_value(PARAM_INT, 'Existing course unit id', VALUE_DEFAULT, 0),
                    'code' => new external_value(PARAM_TEXT, 'Unit code', VALUE_DEFAULT, ''),
                    'name' => new external_value(PARAM_TEXT, 'Unit name', VALUE_DEFAULT, ''),
                    'yearofstudy' => new external_value(PARAM_INT, 'Year of study'),
                    'semestersequence' => new external_value(PARAM_INT, 'Semester number'),
                    'sortorder' => new external_value(PARAM_INT, 'Display order', VALUE_DEFAULT, 0),
                    'unittype' => new external_value(PARAM_ALPHANUMEXT, 'core|elective|optional', VALUE_DEFAULT, 'core'),
                    'isrequired' => new external_value(PARAM_INT, '1 = required', VALUE_DEFAULT, 1),
                    'credithours' => new external_value(PARAM_FLOAT, 'Credit hours', VALUE_DEFAULT, 0),
                ]),
                'Course units assigned to this curriculum',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(int $curriculumid, array $units = []): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'curriculumid' => $curriculumid,
            'units' => $units,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = curriculum_unit_helper::save_assignments(
            (int)$params['curriculumid'],
            $params['units'],
            (int)$USER->id
        );
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
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
