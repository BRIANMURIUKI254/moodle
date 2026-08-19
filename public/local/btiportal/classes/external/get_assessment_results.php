<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;

class get_assessment_results extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Quiz course module id'),
        ]);
    }

    public static function execute(int $cmid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cm = get_coursemodule_from_id('quiz', (int)$params['cmid'], 0, false, MUST_EXIST);
        self::validate_context(\context_course::instance((int)$cm->course));
        return assessment_helper::get_results((int)$params['cmid'], (int)$USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'CM id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'grademax' => new external_value(PARAM_FLOAT, 'Max grade'),
            'students' => new external_value(PARAM_INT, 'Enrolled students'),
            'submitted' => new external_value(PARAM_INT, 'Submitted'),
            'inprogress' => new external_value(PARAM_INT, 'In progress'),
            'notstarted' => new external_value(PARAM_INT, 'Not started'),
            'average' => new external_value(PARAM_FLOAT, 'Average percent'),
            'highest' => new external_value(PARAM_FLOAT, 'Highest percent'),
            'lowest' => new external_value(PARAM_FLOAT, 'Lowest percent'),
            'attempts' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'User id'),
                'fullname' => new external_value(PARAM_TEXT, 'Name'),
                'email' => new external_value(PARAM_TEXT, 'Email'),
                'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
                'attemptnumber' => new external_value(PARAM_INT, 'Attempt number'),
                'state' => new external_value(PARAM_ALPHANUMEXT, 'Portal status'),
                'quizstate' => new external_value(PARAM_ALPHANUMEXT, 'Moodle attempt state'),
                'score' => new external_value(PARAM_FLOAT, 'Score or -1 if none'),
                'grademax' => new external_value(PARAM_FLOAT, 'Max'),
                'percent' => new external_value(PARAM_FLOAT, 'Percent or -1'),
                'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                'timestart' => new external_value(PARAM_INT, 'Start time'),
                'warningcount' => new external_value(PARAM_INT, 'Warnings'),
                'flagged' => new external_value(PARAM_INT, 'Flagged'),
                'autosubmitted' => new external_value(PARAM_INT, 'Auto-submitted'),
            ])),
        ]);
    }
}
