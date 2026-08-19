<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_course_unit_helper;
use local_btiportal\moodle_mapping_diagnostics_helper;

class sync_academic_course_unit_moodle extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Course unit id'),
            'verifyonly' => new external_value(PARAM_BOOL, 'Verify without creating', VALUE_DEFAULT, false),
            'confirmremap' => new external_value(
                PARAM_BOOL,
                'Required when changing an existing Moodle course mapping',
                VALUE_DEFAULT,
                false
            ),
            'targetcourseid' => new external_value(
                PARAM_INT,
                'Moodle course to link when multiple exact matches exist',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(
        int $id,
        bool $verifyonly = false,
        bool $confirmremap = false,
        int $targetcourseid = 0
    ): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            compact('id', 'verifyonly', 'confirmremap', 'targetcourseid')
        );
        academic_auth_helper::require_manage_academic();
        if (!empty($params['verifyonly'])) {
            $result = moodle_mapping_diagnostics_helper::verify_unit((int)$params['id']);
        } else {
            $result = moodle_mapping_diagnostics_helper::sync_unit(
                (int)$params['id'],
                (int)$USER->id,
                !empty($params['confirmremap']),
                (int)$params['targetcourseid']
            );
        }
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_course_unit_helper::structure_keys());
    }
}
