<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\unit_helper;

/**
 * Ensure teacher mapping exists for a given Moodle course section.
 */
class ensure_unit_teacher extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Moodle course section id'),
            'teacheruserid' => new external_value(PARAM_INT, 'User id of the assigned teacher'),
        ]);
    }

    public static function execute(int $courseid, int $sectionid, int $teacheruserid): array {
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'teacheruserid' => $teacheruserid,
        ]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        return unit_helper::ensure_unit_teacher($courseid, $sectionid, $teacheruserid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Course section id'),
            'teacheruserid' => new external_value(PARAM_INT, 'Teacher user id'),
            'created' => new external_value(PARAM_BOOL, 'True if a mapping was created'),
        ]);
    }
}

