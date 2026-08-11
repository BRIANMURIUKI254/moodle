<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;
use local_btiportal\department_auth_helper;

class get_events extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Course ids',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(array $courseids = []): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), ['courseids' => $courseids]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        if (department_auth_helper::is_university_admin((int)$USER->id)) {
            return ['events' => content_helper::list_all_events()];
        }

        $allowed = [];
        foreach ($courseids as $courseid) {
            $courseid = (int)$courseid;
            if ($courseid > 0 && content_helper::user_can_view_course((int)$USER->id, $courseid)) {
                $allowed[] = $courseid;
            }
        }

        $events = content_helper::list_events_for_courses($allowed);
        $authorized = department_auth_helper::get_authorized_department_ids((int)$USER->id);
        $events = array_values(array_filter($events, static function (array $row) use ($authorized): bool {
            $dept = (int)($row['departmentid'] ?? 0);
            $scope = (string)($row['scope'] ?? 'course');
            if ($scope === 'department' || $dept > 0) {
                return in_array($dept, $authorized, true);
            }
            if ($scope === 'university') {
                return false;
            }
            return true;
        }));

        return ['events' => $events];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'events' => new external_multiple_structure(
                new external_single_structure([
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
                    'departmentid' => new external_value(PARAM_INT, 'Department id', VALUE_OPTIONAL),
                    'departmentcode' => new external_value(PARAM_TEXT, 'Department code', VALUE_OPTIONAL),
                    'departmentname' => new external_value(PARAM_TEXT, 'Department name', VALUE_OPTIONAL),
                    'scope' => new external_value(PARAM_TEXT, 'Publication scope', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_TEXT, 'Publication status', VALUE_OPTIONAL),
                ])
            ),
        ]);
    }
}
