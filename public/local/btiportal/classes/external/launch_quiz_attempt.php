<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;

class launch_quiz_attempt extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Moodle quiz instance id'),
            'courseid' => new external_value(PARAM_INT, 'Expected Moodle course id', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Expected course-module id', VALUE_DEFAULT, 0),
            'preflightdata' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Preflight field name'),
                    'value' => new external_value(PARAM_RAW, 'Preflight field value'),
                ]),
                'Quiz preflight data such as a password',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(
        int $quizid,
        int $courseid = 0,
        int $cmid = 0,
        array $preflightdata = []
    ): array {
        global $USER;
        error_log(sprintf(
            'btiportal launch external userid=%d courseid=%d quizid=%d cmid=%d',
            (int)$USER->id,
            $courseid,
            $quizid,
            $cmid
        ));
        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'preflightdata' => $preflightdata,
        ]);
        $quizid = (int)$params['quizid'];
        $courseid = (int)$params['courseid'];
        $cmid = (int)$params['cmid'];

        $result = assessment_helper::launch_quiz_attempt(
            $quizid,
            $courseid,
            $cmid,
            (array)$params['preflightdata'],
            (int)$USER->id
        );

        return [
            'attempt' => $result['attempt'],
            'warnings' => $result['warnings'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attempt' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Attempt id'),
                'quiz' => new external_value(PARAM_INT, 'Quiz id'),
                'userid' => new external_value(PARAM_INT, 'Authenticated owner'),
                'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                'uniqueid' => new external_value(PARAM_INT, 'Question usage id'),
                'state' => new external_value(PARAM_ALPHAEXT, 'Attempt state'),
                'timestart' => new external_value(PARAM_INT, 'Start time'),
                'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                'timemodified' => new external_value(PARAM_INT, 'Modified time'),
            ], 'Attempt', VALUE_OPTIONAL),
            'warnings' => new external_multiple_structure(
                new external_single_structure([
                    'item' => new external_value(PARAM_ALPHA, 'Item'),
                    'itemid' => new external_value(PARAM_INT, 'Item id'),
                    'warningcode' => new external_value(PARAM_TEXT, 'Warning code'),
                    'message' => new external_value(PARAM_TEXT, 'Warning message'),
                ]),
                'Warnings',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
