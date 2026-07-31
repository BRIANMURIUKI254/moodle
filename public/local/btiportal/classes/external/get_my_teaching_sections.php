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
 * Return course sections assigned to the current user as unit teacher.
 */
class get_my_teaching_sections extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), []);

        return ['sections' => unit_helper::get_my_teaching_sections((int)$USER->id)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'sectionid' => new external_value(PARAM_INT, 'Moodle course section id'),
                    'teacheruserid' => new external_value(PARAM_INT, 'Teacher user id'),
                ])
            ),
        ]);
    }
}
