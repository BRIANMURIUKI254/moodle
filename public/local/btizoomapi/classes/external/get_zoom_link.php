<?php
namespace local_btizoomapi\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

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
        global $DB;

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
            return ['found' => false, 'joinurl' => '', 'name' => ''];
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/btizoomapi:getzoomlink', $context);

        // Prefer an explicitly linked Zoom CM for this portal unit (course + section).
        if ($sectionid > 0 && $DB->get_manager()->table_exists('local_btiportal_unit')) {
            $unit = $DB->get_record('local_btiportal_unit', [
                'courseid' => $courseid,
                'sectionid' => $sectionid,
            ], 'id, zoomcmid', IGNORE_MISSING);
            $zoomcmid = $unit ? (int)($unit->zoomcmid ?? 0) : 0;
            if ($zoomcmid > 0) {
                $fromcm = self::zoom_from_cmid($zoomcmid, $courseid);
                if ($fromcm) {
                    return $fromcm;
                }
            }

            // Otherwise use the newest Zoom activity inside this Moodle section.
            $sql = "SELECT z.id, z.name, z.join_url, z.timemodified
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module AND m.name = 'zoom'
                      JOIN {zoom} z ON z.id = cm.instance
                     WHERE cm.course = :courseid
                       AND cm.section = :sectionid
                       AND cm.deletioninprogress = 0
                       AND z.join_url IS NOT NULL
                       AND z.join_url <> :empty
                  ORDER BY z.timemodified DESC, z.id DESC";
            $row = $DB->get_record_sql($sql, [
                'courseid' => $courseid,
                'sectionid' => $sectionid,
                'empty' => '',
            ]);
            if ($row && !empty($row->join_url)) {
                return [
                    'found' => true,
                    'joinurl' => (string) $row->join_url,
                    'name' => (string) $row->name,
                ];
            }

            // Section scoped request with no unit Zoom — do not fall back to another section's meeting.
            return ['found' => false, 'joinurl' => '', 'name' => ''];
        }

        // Course-level: most recently modified Zoom meeting with a real join URL.
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
            return ['found' => false, 'joinurl' => '', 'name' => ''];
        }

        return [
            'found' => true,
            'joinurl' => (string) $zoom->join_url,
            'name' => (string) $zoom->name,
        ];
    }

    /**
     * @return array{found:bool,joinurl:string,name:string}|null
     */
    private static function zoom_from_cmid(int $cmid, int $courseid): ?array {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], '*', IGNORE_MISSING);
        if (!$cm || (int)$cm->deletioninprogress === 1) {
            return null;
        }
        $mod = $DB->get_record('modules', ['id' => (int)$cm->module], 'id, name', IGNORE_MISSING);
        if (!$mod || $mod->name !== 'zoom') {
            return null;
        }
        $zoom = $DB->get_record('zoom', ['id' => (int)$cm->instance], 'id, name, join_url', IGNORE_MISSING);
        if (!$zoom || empty($zoom->join_url)) {
            return null;
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
