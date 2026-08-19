<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\moodle_mapping_diagnostics_helper;

class get_course_unit_moodle_diagnostics extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        academic_auth_helper::require_manage_academic();
        $result = moodle_mapping_diagnostics_helper::diagnose();
        if (empty($result['ok'])) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors'] ?? []));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(
            moodle_mapping_diagnostics_helper::diagnose_structure_keys()
        );
    }
}
