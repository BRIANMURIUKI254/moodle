<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class publish_event extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id (1 or 0 = site-wide)'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'body' => new external_value(PARAM_RAW, 'Body'),
            'timestart' => new external_value(PARAM_INT, 'Unix start time'),
            'timeduration' => new external_value(PARAM_INT, 'Duration seconds', VALUE_DEFAULT, 0),
            'draftitemid' => new external_value(PARAM_INT, 'Draft image item id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $courseid,
        string $title,
        string $body,
        int $timestart,
        int $timeduration = 0,
        int $draftitemid = 0
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'title' => $title,
            'body' => $body,
            'timestart' => $timestart,
            'timeduration' => $timeduration,
            'draftitemid' => $draftitemid,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        $sitewide = $courseid <= 1;
        if ($sitewide) {
            self::validate_context(\context_system::instance());
            if (!is_siteadmin() && !has_capability('moodle/site:config', \context_system::instance())) {
                // Allow managers who can manage site calendar.
                if (!has_capability('moodle/calendar:manageentries', \context_system::instance())) {
                    throw new \moodle_exception('nopermissions', 'error', '', 'publish site event');
                }
            }
            $courseid = (int)SITEID;
        } else {
            self::validate_context(\context_course::instance($courseid));
            if (!content_helper::user_can_manage_course((int)$USER->id, $courseid) && !is_siteadmin()) {
                throw new \moodle_exception('nopermissions', 'error', '', 'publish event');
            }
        }

        if (trim($title) === '' || trim($body) === '') {
            throw new \invalid_parameter_exception('Title and message are required');
        }

        return content_helper::publish_event(
            $courseid,
            (int)$USER->id,
            trim($title),
            trim($body),
            $timestart,
            max(0, $timeduration),
            $draftitemid
        );
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
            'timestart' => new external_value(PARAM_INT, 'Start'),
            'timeduration' => new external_value(PARAM_INT, 'Duration'),
            'eventid' => new external_value(PARAM_INT, 'Calendar event id'),
            'imageurl' => new external_value(PARAM_RAW, 'Image URL'),
            'filename' => new external_value(PARAM_FILE, 'Image file name'),
            'timecreated' => new external_value(PARAM_INT, 'Created'),
        ]);
    }
}
