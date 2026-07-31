<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

/**
 * Create course calendar events for timetable slots (teachers without manageentries).
 */
class create_course_calendar_events extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'events' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Event name'),
                    'description' => new external_value(PARAM_RAW, 'Event description', VALUE_DEFAULT, ''),
                    'timestart' => new external_value(PARAM_INT, 'Unix start time'),
                    'timeduration' => new external_value(PARAM_INT, 'Duration seconds', VALUE_DEFAULT, 0),
                ])
            ),
        ]);
    }

    public static function execute(int $courseid, array $events): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'events' => $events,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        $course = get_course($courseid);
        self::validate_context(\context_course::instance($course->id));

        if (!content_helper::user_can_manage_course((int)$USER->id, $courseid)
            && !is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'create course calendar events');
        }

        $created = [];
        foreach ($events as $event) {
            $created[] = content_helper::create_course_calendar_event(
                $courseid,
                (string)$event['name'],
                (string)($event['description'] ?? ''),
                (int)$event['timestart'],
                (int)($event['timeduration'] ?? 0)
            );
        }

        return ['events' => $created];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'events' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Event id'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'timestart' => new external_value(PARAM_INT, 'Start'),
                    'timeduration' => new external_value(PARAM_INT, 'Duration'),
                    'eventtype' => new external_value(PARAM_ALPHA, 'Event type'),
                ])
            ),
        ]);
    }
}
