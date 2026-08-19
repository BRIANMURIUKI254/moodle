<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Cascade-clean local_btiportal rows that reference a Moodle course, then delete the course.
 */
class moodle_course_cleanup_helper {
    /**
     * Remove portal traces for a Moodle course id (timetable, content, mappings, etc.).
     *
     * @return array<string,int> Counts of rows removed/cleared per table
     */
    public static function purge_local_traces(int $courseid): array {
        global $DB;

        if ($courseid <= 1) {
            throw new \invalid_parameter_exception('Cannot purge site course');
        }

        $counts = [];

        // Hard-delete course-scoped operational/content rows.
        $deletebycourse = [
            'local_btiportal_timetable' => 'moodlecourseid',
            'local_btiportal_notification' => 'moodlecourseid',
            'local_btiportal_announcement' => 'courseid',
            'local_btiportal_event' => 'courseid',
            'local_btiportal_recording' => 'courseid',
            'local_btiportal_certificate' => 'courseid',
            'local_btiportal_assignment_meta' => 'courseid',
            'local_btiportal_assessment_meta' => 'courseid',
            'local_btiportal_exam_event' => 'courseid',
            'local_btiportal_inactive_alert' => 'courseid',
            'local_btiportal_unit' => 'courseid',
            'local_btiportal_unit_gradeitem' => 'courseid',
        ];

        foreach ($deletebycourse as $table => $column) {
            if (!$DB->get_manager()->table_exists($table)) {
                $counts[$table] = 0;
                continue;
            }
            $counts[$table] = (int)$DB->count_records($table, [$column => $courseid]);
            if ($counts[$table] > 0) {
                $DB->delete_records($table, [$column => $courseid]);
            }
        }

        // Resources + their completion rows.
        if ($DB->get_manager()->table_exists('local_btiportal_resource')) {
            $resourceids = $DB->get_fieldset_select(
                'local_btiportal_resource',
                'id',
                'courseid = ?',
                [$courseid]
            );
            $counts['local_btiportal_resource'] = count($resourceids);
            if ($resourceids && $DB->get_manager()->table_exists('local_btiportal_resource_completion')) {
                list($insql, $params) = $DB->get_in_or_equal($resourceids);
                $DB->delete_records_select('local_btiportal_resource_completion', "resourceid {$insql}", $params);
            }
            if ($resourceids) {
                $DB->delete_records('local_btiportal_resource', ['courseid' => $courseid]);
            }
        } else {
            $counts['local_btiportal_resource'] = 0;
        }

        // Academic course-unit Moodle mapping: clear, do not delete the academic unit.
        if ($DB->get_manager()->table_exists('local_btiportal_course_unit')) {
            $mapped = $DB->get_records('local_btiportal_course_unit', ['courseid' => $courseid]);
            $counts['local_btiportal_course_unit_cleared'] = count($mapped);
            foreach ($mapped as $unit) {
                $unit->courseid = 0;
                $unit->sectionid = 0;
                $unit->timemodified = time();
                $DB->update_record('local_btiportal_course_unit', $unit);
            }
        } else {
            $counts['local_btiportal_course_unit_cleared'] = 0;
        }

        return $counts;
    }

    /**
     * Delete a Moodle course after cascade cleanup.
     * Loads backup libs (needed by recycle bin) and falls back to recycle-bin-off delete.
     *
     * @return array{courseid:int,deleted:bool,warnings:list<string>,purged:array<string,int>}
     */
    public static function delete_moodle_course(int $courseid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        if ($courseid <= (int)SITEID) {
            throw new \moodle_exception('cannotdeletecourse', 'error');
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return [
                'courseid' => $courseid,
                'deleted' => true,
                'warnings' => ['Course already absent'],
                'purged' => [],
            ];
        }

        if (!empty($course->deletioninprogress)) {
            $DB->set_field('course', 'deletioninprogress', 0, ['id' => $courseid]);
            $course->deletioninprogress = 0;
        }

        $purged = self::purge_local_traces($courseid);
        $warnings = [];

        // Prefer sync delete (avoid stuck deletioninprogress if cron is unhealthy).
        $prevasync = get_config('moodlecourse', 'enablecourseasyncdeletion');
        set_config('enablecourseasyncdeletion', 0, 'moodlecourse');

        $deleted = false;
        try {
            $deleted = (bool)delete_course($course, false);
        } catch (\Throwable $e) {
            // Recycle-bin backup path can fail if backup classes/config are broken.
            // Retry with category recycle bin temporarily disabled.
            $warnings[] = 'Primary delete failed: ' . $e->getMessage();
            $prevenv = get_config('tool_recyclebin', 'categorybinenable');
            set_config('categorybinenable', 0, 'tool_recyclebin');
            try {
                // Reload course in case first attempt partially mutated it.
                $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
                if (!$course) {
                    $deleted = true;
                } else {
                    if (!empty($course->deletioninprogress)) {
                        $DB->set_field('course', 'deletioninprogress', 0, ['id' => $courseid]);
                        $course->deletioninprogress = 0;
                    }
                    $deleted = (bool)delete_course($course, false);
                }
            } finally {
                if ($prevenv === false || $prevenv === null) {
                    unset_config('categorybinenable', 'tool_recyclebin');
                } else {
                    set_config('categorybinenable', $prevenv, 'tool_recyclebin');
                }
            }
        } finally {
            if ($prevasync === false || $prevasync === null) {
                unset_config('enablecourseasyncdeletion', 'moodlecourse');
            } else {
                set_config('enablecourseasyncdeletion', $prevasync, 'moodlecourse');
            }
        }

        if ($deleted) {
            fix_course_sortorder();
        }

        // Ensure no orphaned portal rows remain if delete partially ran.
        if ($deleted || !$DB->record_exists('course', ['id' => $courseid])) {
            $purged = array_merge($purged, self::purge_local_traces($courseid));
            $deleted = true;
        }

        return [
            'courseid' => $courseid,
            'deleted' => $deleted,
            'warnings' => $warnings,
            'purged' => $purged,
        ];
    }
}
