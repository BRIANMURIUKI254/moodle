<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class get_recordings extends external_api {
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

        $allowed = [];
        foreach ($courseids as $courseid) {
            $courseid = (int)$courseid;
            if ($courseid > 0 && content_helper::user_can_view_course((int)$USER->id, $courseid)) {
                $allowed[] = $courseid;
            }
        }

        return ['recordings' => content_helper::list_recordings_for_courses($allowed)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recordings' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Id'),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'sectionid' => new external_value(PARAM_INT, 'Unit section id'),
                    'unitname' => new external_value(PARAM_TEXT, 'Unit name'),
                    'coursecode' => new external_value(PARAM_TEXT, 'Course code'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                    'authorid' => new external_value(PARAM_INT, 'Author id'),
                    'authorname' => new external_value(PARAM_TEXT, 'Author name'),
                    'title' => new external_value(PARAM_TEXT, 'Title'),
                    'type' => new external_value(PARAM_ALPHA, 'Type'),
                    'sessiondate' => new external_value(PARAM_TEXT, 'Session date'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'link' => new external_value(PARAM_RAW, 'Link'),
                    'filename' => new external_value(PARAM_FILE, 'File name'),
                    'timecreated' => new external_value(PARAM_INT, 'Created'),
                ])
            ),
        ]);
    }
}
