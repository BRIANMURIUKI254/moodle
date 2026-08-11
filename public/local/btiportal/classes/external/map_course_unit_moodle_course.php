<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\curriculum_delivery_helper;

class map_course_unit_moodle_course extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseunitid' => new external_value(PARAM_INT, 'Course unit id'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'sectionid' => new external_value(PARAM_INT, 'Moodle section id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $courseunitid, int $courseid, int $sectionid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'courseunitid', 'courseid', 'sectionid'
        ));
        academic_auth_helper::require_manage_academic();
        $result = curriculum_delivery_helper::map_moodle_course(
            (int)$params['courseunitid'],
            (int)$params['courseid'],
            (int)$params['sectionid'],
            (int)$USER->id
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        $enriched = curriculum_delivery_helper::enrich_unit($result['record']);
        return academic_api_response::for_external(academic_api_response::ok($enriched));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(curriculum_delivery_helper::enriched_unit_structure_keys());
    }
}
