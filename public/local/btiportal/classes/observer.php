<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers for BTI portal course bootstrap.
 */
class observer {
    /**
     * After a course is created, ensure a General (non-news) forum exists.
     *
     * @param \core\event\course_created $event
     */
    public static function course_created(\core\event\course_created $event): void {
        $courseid = (int) $event->objectid;
        if ($courseid <= 1) {
            return;
        }
        try {
            forum_helper::ensure_general_forum($courseid);
        } catch (\Throwable $e) {
            debugging('local_btiportal course_created forum ensure failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Remove portal rows when a Moodle course is deleted.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $courseid = (int) $event->objectid;
        if ($courseid <= 1) {
            return;
        }

        $tables = [
            'local_btiportal_unit',
            'local_btiportal_unit_gradeitem',
            'local_btiportal_assignment_meta',
            'local_btiportal_announcement',
            'local_btiportal_event',
            'local_btiportal_recording',
            'local_btiportal_certificate',
        ];

        foreach ($tables as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            try {
                $DB->delete_records($table, ['courseid' => $courseid]);
            } catch (\Throwable $e) {
                debugging("local_btiportal course_deleted cleanup {$table}: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($DB->get_manager()->table_exists('local_btiportal_resource')) {
            try {
                $ids = $DB->get_fieldset_select('local_btiportal_resource', 'id', 'courseid = ?', [$courseid]);
                if (!empty($ids) && $DB->get_manager()->table_exists('local_btiportal_resource_completion')) {
                    list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $ids));
                    $DB->delete_records_select('local_btiportal_resource_completion', "resourceid {$insql}", $params);
                }
                $DB->delete_records('local_btiportal_resource', ['courseid' => $courseid]);
            } catch (\Throwable $e) {
                debugging('local_btiportal course_deleted resource cleanup: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Drop assignment_meta when a Moodle assign CM is deleted.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        $other = $event->other ?? [];
        $modulename = (string)($other['modulename'] ?? '');
        $instanceid = (int)($other['instanceid'] ?? 0);
        if ($modulename !== 'assign' || $instanceid <= 0) {
            return;
        }
        try {
            assignment_meta_helper::delete_for_assignment($instanceid);
        } catch (\Throwable $e) {
            debugging('local_btiportal assign meta cleanup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
