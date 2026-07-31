<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class publish_resource extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Unit section id'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'link' => new external_value(PARAM_RAW, 'Optional link', VALUE_DEFAULT, ''),
            'filename' => new external_value(PARAM_FILE, 'Original file name', VALUE_DEFAULT, ''),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $courseid,
        int $sectionid,
        string $title,
        string $description = '',
        string $link = '',
        string $filename = '',
        int $draftitemid = 0
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'title' => $title,
            'description' => $description,
            'link' => $link,
            'filename' => $filename,
            'draftitemid' => $draftitemid,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        self::validate_context(\context_course::instance($courseid));
        if (!content_helper::user_can_manage_course((int)$USER->id, $courseid) && !is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'publish resource');
        }

        if (trim($title) === '') {
            throw new \invalid_parameter_exception('Title is required');
        }

        return content_helper::publish_resource(
            $courseid,
            $sectionid,
            (int)$USER->id,
            trim($title),
            trim($description),
            trim($link),
            trim($filename),
            $draftitemid
        );
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
