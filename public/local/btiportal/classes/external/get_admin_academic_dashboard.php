<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_dashboard_helper;

class get_admin_academic_dashboard extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }
    public static function execute(): array {
        academic_auth_helper::require_view_academic();
        $data = academic_dashboard_helper::get_admin_academic_dashboard();
        return academic_api_response::for_external(academic_api_response::ok($data));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_dashboard_helper::structure_keys());
    }
}
