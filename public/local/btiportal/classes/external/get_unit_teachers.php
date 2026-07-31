<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\unit_helper;

/**
 * Return unit (section) → teacher mappings for a course.
 */
class get_unit_teachers extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    public static function execute(int $courseid): array {
        self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:view', $context);

        return ['units' => unit_helper::get_unit_teachers($courseid)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'units' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'sectionid' => new external_value(PARAM_INT, 'Moodle course section id'),
                    'teacheruserid' => new external_value(PARAM_INT, 'Teacher user id'),
                    'teachername' => new external_value(PARAM_TEXT, 'Teacher display name'),
                    'teacheremail' => new external_value(PARAM_EMAIL, 'Teacher email'),
                ])
            ),
        ]);
    }
}
