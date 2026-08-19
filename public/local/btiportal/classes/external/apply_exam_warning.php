<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\exam_monitor_helper;

class apply_exam_warning extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'TAB_HIDDEN or FULLSCREEN_EXIT', VALUE_DEFAULT, 'TAB_HIDDEN'),
        ]);
    }

    public static function execute(int $attemptid, string $reason = 'TAB_HIDDEN'): array {
        global $USER, $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'reason' => $reason,
        ]);
        $quizid = (int)$DB->get_field('quiz_attempts', 'quiz', ['id' => (int)$params['attemptid']], MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quizid, 0, false, MUST_EXIST);
        self::validate_context(\context_module::instance((int)$cm->id));
        return exam_monitor_helper::apply_warning((int)$USER->id, (int)$params['attemptid'], (string)$params['reason']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt'),
            'warningcount' => new external_value(PARAM_INT, 'Warnings'),
            'maxwarnings' => new external_value(PARAM_INT, 'Max'),
            'flagged' => new external_value(PARAM_INT, 'Flagged'),
            'autosubmitted' => new external_value(PARAM_INT, 'Auto-submitted'),
            'submitted' => new external_value(PARAM_INT, 'Submitted now'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Configured action'),
            'executed' => new external_value(PARAM_ALPHANUMEXT, 'Action executed'),
            'atlimit' => new external_value(PARAM_INT, 'Reached max warnings'),
        ]);
    }
}
