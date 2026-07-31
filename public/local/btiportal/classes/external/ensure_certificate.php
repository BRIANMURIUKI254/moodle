<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class ensure_certificate extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'portalbaseurl' => new external_value(PARAM_TEXT, 'Portal origin for QR verify links', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $courseid, string $portalbaseurl = ''): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'portalbaseurl' => $portalbaseurl,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        $cert = content_helper::ensure_certificate_for_course((int)$USER->id, $courseid, $portalbaseurl);
        if (!$cert) {
            return [
                'issued' => false,
                'id' => 0,
                'userid' => (int)$USER->id,
                'courseid' => $courseid,
                'studentname' => '',
                'coursename' => '',
                'coursecode' => '',
                'completedat' => 0,
                'fileurl' => '',
                'filename' => '',
                'timecreated' => 0,
            ];
        }

        return array_merge(['issued' => true], $cert);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'issued' => new external_value(PARAM_BOOL, 'Whether a certificate exists or was issued'),
            'id' => new external_value(PARAM_INT, 'Id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'studentname' => new external_value(PARAM_TEXT, 'Student name'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'coursecode' => new external_value(PARAM_TEXT, 'Course code'),
            'completedat' => new external_value(PARAM_INT, 'Completion unix time'),
            'fileurl' => new external_value(PARAM_RAW, 'Moodle pluginfile URL'),
            'filename' => new external_value(PARAM_FILE, 'PDF filename'),
            'timecreated' => new external_value(PARAM_INT, 'Created'),
        ]);
    }
}
