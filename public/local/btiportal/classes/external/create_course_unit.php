<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\unit_helper;

/**
 * Create a new Moodle course section (portal unit).
 */
class create_course_unit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'name' => new external_value(PARAM_TEXT, 'Unit / section name'),
        ]);
    }

    public static function execute(int $courseid, string $name): array {
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
        ]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);

        return unit_helper::create_course_unit($courseid, $name);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Course section id'),
            'section' => new external_value(PARAM_INT, 'Section number'),
            'name' => new external_value(PARAM_TEXT, 'Unit name'),
        ]);
    }
}
