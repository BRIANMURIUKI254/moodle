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
use local_btiportal\student_profile_helper;

class list_programme_students extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Optional status filter', VALUE_DEFAULT, ''),
            'curriculumid' => new external_value(PARAM_INT, 'Optional curriculum filter', VALUE_DEFAULT, 0),
        ]);
    }
    public static function execute(int $programmeid, string $status = '', int $curriculumid = 0): array {
        self::validate_parameters(self::execute_parameters(), [
            'programmeid' => $programmeid,
            'status' => $status,
            'curriculumid' => $curriculumid,
        ]);
        academic_auth_helper::require_view_academic();
        $items = student_profile_helper::list_by_programme(
            $programmeid,
            $status !== '' ? $status : null,
            $curriculumid > 0 ? $curriculumid : null
        );
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(student_profile_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
