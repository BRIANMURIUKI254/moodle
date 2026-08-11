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
use local_btiportal\curriculum_helper;

class list_curricula extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Filter by programme', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Filter by status', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(int $programmeid = 0, string $status = ''): array {
        self::validate_parameters(self::execute_parameters(), compact('programmeid', 'status'));
        academic_auth_helper::require_view_academic();
        $items = curriculum_helper::list(
            $programmeid > 0 ? $programmeid : null,
            $status !== '' ? $status : null
        );
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(curriculum_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
