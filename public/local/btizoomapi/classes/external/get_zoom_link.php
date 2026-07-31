<?php
namespace local_btizoomapi\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Return a Zoom join URL for a Moodle Zoom activity (by cmid) or course.
 */
class get_zoom_link extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Zoom course module id (preferred)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $courseid = 0, int $cmid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
        ]);
        $courseid = (int) $params['courseid'];
        $cmid = (int) $params['cmid'];

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        $empty = ['found' => false, 'joinurl' => '', 'name' => ''];

        // Preferred path: exact Zoom activity via course module id.
        if ($cmid > 0) {
            $sql = "SELECT z.id, z.name, z.join_url, z.course, cm.id AS cmid
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module AND m.name = 'zoom'
                      JOIN {zoom} z ON z.id = cm.instance
                     WHERE cm.id = ?";
            $zoom = $DB->get_record_sql($sql, [$cmid]);
            if (!$zoom) {
                return $empty;
            }

            $context = \context_course::instance((int) $zoom->course);
            self::validate_context($context);
            require_capability('local/btizoomapi:getzoomlink', $context);

            if (empty($zoom->join_url)) {
                return $empty;
            }

            return [
                'found' => true,
                'joinurl' => (string) $zoom->join_url,
                'name' => (string) $zoom->name,
            ];
        }

        // Backward-compatible fallback: newest Zoom activity in the course.
        // Ambiguous when multiple Zoom activities exist — prefer passing cmid.
        if ($courseid <= 1) {
            return $empty;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/btizoomapi:getzoomlink', $context);

        $candidates = $DB->get_records_select(
            'zoom',
            'course = ? AND join_url IS NOT NULL AND join_url <> ?',
            [$courseid, ''],
            'timemodified DESC, id DESC',
            'id, name, join_url, meeting_id, timemodified',
            0,
            1
        );
        $zoom = $candidates ? reset($candidates) : false;

        if (!$zoom || empty($zoom->join_url)) {
            return $empty;
        }

        return [
            'found' => true,
            'joinurl' => (string) $zoom->join_url,
            'name' => (string) $zoom->name,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether a Zoom meeting with a join URL was found'),
            'joinurl' => new external_value(PARAM_RAW, 'Zoom join URL (empty when not found)'),
            'name' => new external_value(PARAM_TEXT, 'Zoom activity name (empty when not found)'),
        ]);
    }
}
