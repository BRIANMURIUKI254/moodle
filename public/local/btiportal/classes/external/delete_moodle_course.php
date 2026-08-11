<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\moodle_course_cleanup_helper;

/**
 * Delete a Moodle course and purge local_btiportal traces (timetable, content, mappings).
 */
class delete_moodle_course extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id to delete'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_system::instance();
        self::validate_context($context);

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if ($coursecontext) {
            require_capability('moodle/course:delete', $coursecontext);
        } else {
            require_capability('moodle/course:delete', $context);
        }

        if (!is_siteadmin($USER) && !has_capability('local/btiportal:manageacademic', $context)) {
            // Keep parity with admin portal delete: managers / academic admins.
            if (!$coursecontext || !can_delete_course($courseid)) {
                throw new \moodle_exception('cannotdeletecourse', 'error');
            }
        }

        $result = moodle_course_cleanup_helper::delete_moodle_course($courseid);
        if (!$result['deleted']) {
            throw new \moodle_exception('cannotdeletecategorycourse', 'error', '', $courseid);
        }

        $purgedrows = [];
        foreach ($result['purged'] as $table => $count) {
            $purgedrows[] = ['table' => (string)$table, 'count' => (int)$count];
        }

        return [
            'courseid' => (int)$result['courseid'],
            'deleted' => true,
            'warnings' => array_values($result['warnings']),
            'purged' => $purgedrows,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Deleted course id'),
            'deleted' => new external_value(PARAM_BOOL, 'True when course was removed'),
            'warnings' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Warning message'),
                'Non-fatal warnings during delete',
                VALUE_DEFAULT,
                []
            ),
            'purged' => new external_multiple_structure(
                new external_single_structure([
                    'table' => new external_value(PARAM_TEXT, 'Table name'),
                    'count' => new external_value(PARAM_INT, 'Rows removed/cleared'),
                ]),
                'Cascade purge summary'
            ),
        ]);
    }
}
