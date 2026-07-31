<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class publish_announcement extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'body' => new external_value(PARAM_RAW, 'Body'),
        ]);
    }

    public static function execute(int $courseid, string $title, string $body): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'title' => $title,
            'body' => $body,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        self::validate_context(\context_course::instance($courseid));
        if (!content_helper::user_can_manage_course((int)$USER->id, $courseid) && !is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'publish announcement');
        }

        return content_helper::publish_announcement($courseid, (int)$USER->id, trim($title), trim($body));
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'coursecode' => new external_value(PARAM_TEXT, 'Course code'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'authorid' => new external_value(PARAM_INT, 'Author id'),
            'authorname' => new external_value(PARAM_TEXT, 'Author name'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'body' => new external_value(PARAM_RAW, 'Body'),
            'eventid' => new external_value(PARAM_INT, 'Calendar event id'),
            'timecreated' => new external_value(PARAM_INT, 'Created'),
        ]);
    }
}
