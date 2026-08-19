<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\exam_monitor_helper;

class log_exam_event extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'eventtype' => new external_value(PARAM_ALPHANUMEXT, 'Event type'),
            'details' => new external_value(PARAM_TEXT, 'Optional detail', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $quizid, int $attemptid, string $eventtype, string $details = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid,
            'attemptid' => $attemptid,
            'eventtype' => $eventtype,
            'details' => $details,
        ]);
        $cm = get_coursemodule_from_instance('quiz', (int)$params['quizid'], 0, false, MUST_EXIST);
        self::validate_context(\context_module::instance((int)$cm->id));
        return exam_monitor_helper::log_event(
            (int)$USER->id,
            (int)$params['quizid'],
            (int)$params['attemptid'],
            (string)$params['eventtype'],
            (string)$params['details']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Event id'),
            'eventtype' => new external_value(PARAM_ALPHANUMEXT, 'Type'),
            'warningcount' => new external_value(PARAM_INT, 'Current warnings'),
            'flagged' => new external_value(PARAM_INT, 'Flagged'),
            'timecreated' => new external_value(PARAM_INT, 'Time'),
        ]);
    }
}
