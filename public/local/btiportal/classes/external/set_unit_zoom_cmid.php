<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\unit_helper;

/**
 * Set (or clear) the Zoom course-module id linked to a portal unit.
 */
class set_unit_zoom_cmid extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Moodle course section id'),
            'zoomcmid' => new external_value(PARAM_INT, 'Zoom course module id (0 to clear)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $courseid, int $sectionid, int $zoomcmid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'zoomcmid' => $zoomcmid,
        ]);

        $context = \context_course::instance((int) $params['courseid']);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        return unit_helper::set_unit_zoom_cmid(
            (int) $params['courseid'],
            (int) $params['sectionid'],
            (int) $params['zoomcmid']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Section id'),
            'zoomcmid' => new external_value(PARAM_INT, 'Stored Zoom course module id (0 if cleared)'),
        ]);
    }
}
