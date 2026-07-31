<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;

class get_certificates extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Optional course filter',
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

        return [
            'certificates' => content_helper::list_certificates_for_user((int)$USER->id, $courseids),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'certificates' => new external_multiple_structure(
                new external_single_structure([
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
                ])
            ),
        ]);
    }
}
