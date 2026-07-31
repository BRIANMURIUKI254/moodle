<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\forum_helper;

/**
 * Ensure a non-news general discussion forum exists on a course.
 */
class ensure_general_forum extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $DB;

        self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        $before = $DB->get_record('forum', [
            'course' => $courseid,
            'type' => 'general',
        ], '*', IGNORE_MULTIPLE);

        $forumid = forum_helper::ensure_general_forum($courseid);

        return [
            'forumid' => $forumid,
            'created' => empty($before),
            'name' => forum_helper::GENERAL_FORUM_NAME,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'forumid' => new external_value(PARAM_INT, 'Forum instance id'),
            'created' => new external_value(PARAM_BOOL, 'True if a new forum was created'),
            'name' => new external_value(PARAM_TEXT, 'Forum name'),
        ]);
    }
}
