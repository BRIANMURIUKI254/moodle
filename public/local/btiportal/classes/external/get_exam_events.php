<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\exam_monitor_helper;

class get_exam_events extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Quiz course module id'),
            'attemptid' => new external_value(PARAM_INT, 'Optional attempt filter', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $cmid, int $attemptid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'attemptid' => $attemptid,
        ]);
        $cm = get_coursemodule_from_id('quiz', (int)$params['cmid'], 0, false, MUST_EXIST);
        self::validate_context(\context_course::instance((int)$cm->course));
        return [
            'events' => exam_monitor_helper::list_events(
                (int)$params['cmid'],
                (int)$USER->id,
                (int)$params['attemptid']
            ),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'events' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Id'),
                'quizid' => new external_value(PARAM_INT, 'Quiz'),
                'cmid' => new external_value(PARAM_INT, 'CM'),
                'attemptid' => new external_value(PARAM_INT, 'Attempt'),
                'userid' => new external_value(PARAM_INT, 'User'),
                'eventtype' => new external_value(PARAM_ALPHANUMEXT, 'Type'),
                'warningcount' => new external_value(PARAM_INT, 'Warnings at event'),
                'details' => new external_value(PARAM_TEXT, 'Details'),
                'timecreated' => new external_value(PARAM_INT, 'Time'),
            ])),
        ]);
    }
}
