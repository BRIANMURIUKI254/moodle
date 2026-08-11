<?php
namespace local_btizoomapi\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btizoomapi\zoom_link_helper;

/**
 * Ensure a Zoom join URL exists for a Moodle course section (portal course unit).
 * Creates a recurring no-fixed-time Zoom activity in that section when missing.
 */
class ensure_zoom_link extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'sectionid' => new external_value(PARAM_INT, 'Moodle course section id (course unit topic)'),
            'name' => new external_value(
                PARAM_TEXT,
                'Optional Zoom activity display name (defaults to section / unit name)',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    public static function execute(int $courseid, int $sectionid, string $name = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'name' => $name,
        ]);
        $courseid = (int) $params['courseid'];
        $sectionid = (int) $params['sectionid'];
        $name = (string) $params['name'];

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        if ($courseid <= 1 || $sectionid <= 0) {
            throw new \invalid_parameter_exception('courseid and sectionid are required to generate a course-unit Zoom link');
        }

        $course = get_course($courseid);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/btizoomapi:ensurezoomlink', $context);
        require_capability('mod/zoom:addinstance', $context);

        return zoom_link_helper::ensure($courseid, $sectionid, $name);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(zoom_link_helper::result_structure_keys());
    }
}
