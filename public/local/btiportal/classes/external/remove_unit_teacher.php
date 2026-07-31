<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\unit_helper;

/**
 * Remove the portal unit teacher mapping so another instructor can be assigned.
 */
class remove_unit_teacher extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Moodle course section id'),
        ]);
    }

    public static function execute(int $courseid, int $sectionid): array {
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        return unit_helper::remove_unit_teacher($courseid, $sectionid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Course section id'),
            'removed' => new external_value(PARAM_BOOL, 'True if a mapping was removed'),
        ]);
    }
}
