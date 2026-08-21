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
use local_btiportal\student_programme_course_helper;

/**
 * Student-safe mapping of enrolled Moodle courses → academic programme containers.
 * Does not require local/btiportal:viewacademic.
 */
class get_my_enrolled_programme_courses extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $items = student_programme_course_helper::mappings_for_userid($userid);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(student_programme_course_helper::item_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
