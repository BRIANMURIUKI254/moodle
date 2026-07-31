<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class publish_recording extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'type' => new external_value(PARAM_ALPHA, 'class or event', VALUE_DEFAULT, 'class'),
            'sessiondate' => new external_value(PARAM_TEXT, 'Session date YYYY-MM-DD'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'link' => new external_value(PARAM_RAW, 'Video / meeting URL', VALUE_DEFAULT, ''),
            'filename' => new external_value(PARAM_FILE, 'Original file name', VALUE_DEFAULT, ''),
            'sectionid' => new external_value(PARAM_INT, 'Unit section id', VALUE_DEFAULT, 0),
            'unitname' => new external_value(PARAM_TEXT, 'Unit name', VALUE_DEFAULT, ''),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item id from webservice/upload.php', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $courseid,
        string $title,
        string $type = 'class',
        string $sessiondate = '',
        string $description = '',
        string $link = '',
        string $filename = '',
        int $sectionid = 0,
        string $unitname = '',
        int $draftitemid = 0
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'title' => $title,
            'type' => $type,
            'sessiondate' => $sessiondate,
            'description' => $description,
            'link' => $link,
            'filename' => $filename,
            'sectionid' => $sectionid,
            'unitname' => $unitname,
            'draftitemid' => $draftitemid,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        self::validate_context(\context_course::instance($courseid));
        if (!content_helper::user_can_manage_course((int)$USER->id, $courseid) && !is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'publish recording');
        }

        if (trim($title) === '' || trim($sessiondate) === '') {
            throw new \invalid_parameter_exception('Title and session date are required');
        }
        if ($draftitemid <= 0 && trim($link) === '') {
            throw new \invalid_parameter_exception('Upload a video file or provide a video link');
        }

        return content_helper::publish_recording(
            $courseid,
            (int)$USER->id,
            trim($title),
            $type,
            trim($sessiondate),
            trim($description),
            trim($link),
            trim($filename),
            $sectionid,
            trim($unitname),
            $draftitemid
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
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
        ]);
    }
}
