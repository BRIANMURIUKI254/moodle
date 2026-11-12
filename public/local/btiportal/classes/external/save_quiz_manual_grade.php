<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\quiz_grading_helper;

class save_quiz_manual_grade extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id'),
            'courseid' => new external_value(PARAM_INT, 'Expected course id'),
            'quizid' => new external_value(PARAM_INT, 'Expected quiz id'),
            'slot' => new external_value(PARAM_INT, 'Question slot'),
            'mark' => new external_value(PARAM_FLOAT, 'Question mark'),
            'feedback' => new external_value(PARAM_RAW, 'Lecturer feedback', VALUE_DEFAULT, ''),
            'expectedversion' => new external_value(PARAM_INT, 'Question step id from the loaded attempt; 0 skips the conflict check', VALUE_DEFAULT, 0),
            'commentformat' => new external_value(PARAM_INT, 'Moodle text format for the comment', VALUE_DEFAULT, FORMAT_PLAIN),
        ]);
    }

    public static function execute(
        int $attemptid,
        int $courseid,
        int $quizid,
        int $slot,
        float $mark,
        string $feedback = '',
        int $expectedversion = 0,
        int $commentformat = FORMAT_PLAIN
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'courseid' => $courseid,
            'quizid' => $quizid,
            'slot' => $slot,
            'mark' => $mark,
            'feedback' => $feedback,
            'expectedversion' => $expectedversion,
            'commentformat' => $commentformat,
        ]);
        $cm = get_coursemodule_from_instance(
            'quiz',
            (int)$params['quizid'],
            (int)$params['courseid'],
            false,
            MUST_EXIST
        );
        self::validate_context(\context_module::instance((int)$cm->id));
        return quiz_grading_helper::save_manual_grade(
            (int)$params['attemptid'],
            (int)$params['courseid'],
            (int)$params['quizid'],
            (int)$params['slot'],
            (float)$params['mark'],
            (string)$params['feedback'],
            (int)$USER->id,
            (int)$params['expectedversion'],
            (int)$params['commentformat']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'slot' => new external_value(PARAM_INT, 'Question slot'),
            'mark' => new external_value(PARAM_FLOAT, 'Saved question mark'),
            'feedback' => new external_value(PARAM_RAW, 'Saved manual feedback'),
            'attemptsumgrades' => new external_value(PARAM_FLOAT, 'Updated raw attempt total'),
            'quizgrade' => new external_value(PARAM_FLOAT, 'Updated overall quiz grade; -1 if absent'),
            'unchanged' => new external_value(PARAM_BOOL, 'True when the mark and comment were already saved', VALUE_DEFAULT, false),
            'notified' => new external_value(PARAM_BOOL, 'True when the student was notified after release', VALUE_DEFAULT, false),
            'version' => new external_value(PARAM_INT, 'Question step id after the save', VALUE_DEFAULT, 0),
        ]);
    }
}
