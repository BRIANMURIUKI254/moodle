<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class set_resource_completion extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resourceid' => new external_value(PARAM_INT, 'Resource id'),
            'completed' => new external_value(PARAM_BOOL, 'Mark complete'),
        ]);
    }

    public static function execute(int $resourceid, bool $completed): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'resourceid' => $resourceid,
            'completed' => $completed,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        return content_helper::set_resource_completion($resourceid, (int)$USER->id, $completed);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Section id'),
            'coursecode' => new external_value(PARAM_TEXT, 'Course code'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'authorid' => new external_value(PARAM_INT, 'Author id'),
            'authorname' => new external_value(PARAM_TEXT, 'Author name'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'description' => new external_value(PARAM_RAW, 'Description'),
            'link' => new external_value(PARAM_RAW, 'Link'),
            'filename' => new external_value(PARAM_FILE, 'File name'),
            'completed' => new external_value(PARAM_BOOL, 'Completed by current user'),
            'timecreated' => new external_value(PARAM_INT, 'Created'),
        ]);
    }
}
