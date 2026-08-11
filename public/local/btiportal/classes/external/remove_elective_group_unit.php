<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\elective_helper;

class remove_elective_group_unit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'electivegroupid' => new external_value(PARAM_INT, 'Elective group id'),
            'courseunitid' => new external_value(PARAM_INT, 'Course unit id'),
        ]);
    }

    public static function execute(int $electivegroupid, int $courseunitid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'electivegroupid' => $electivegroupid,
            'courseunitid' => $courseunitid,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = elective_helper::remove_unit_from_group(
            (int)$params['electivegroupid'],
            (int)$params['courseunitid']
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail(
                $result['errors'] ?? [['field' => '', 'message' => 'Remove failed']]
            ));
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'electivegroupid' => (int)$params['electivegroupid'],
            'courseunitid' => (int)$params['courseunitid'],
            'removed' => 1,
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'electivegroupid' => new external_value(PARAM_INT, 'electivegroupid'),
            'courseunitid' => new external_value(PARAM_INT, 'courseunitid'),
            'removed' => new external_value(PARAM_INT, 'removed'),
        ]);
    }
}
