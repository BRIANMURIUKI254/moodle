<?php
namespace local_btizoomapi\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btizoomapi\zoom_link_helper;

/**
 * Return a Zoom join URL for a Moodle course (optionally scoped to a course section / unit).
 */
class get_zoom_link extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'sectionid' => new external_value(
                PARAM_INT,
                'Optional Moodle course section id (portal course unit mapping)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(int $courseid, int $sectionid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ]);
        $courseid = (int) $params['courseid'];
        $sectionid = (int) $params['sectionid'];

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        if ($courseid <= 1) {
            return zoom_link_helper::empty_result();
        }

        $course = get_course($courseid);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/btizoomapi:getzoomlink', $context);

        return zoom_link_helper::find($courseid, $sectionid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(zoom_link_helper::result_structure_keys());
    }
}
