<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\quiz_grading_helper;

class get_quiz_grading_queue extends external_api {
    public static function execute_parameters(): \core_external\external_function_parameters {
        return new \core_external\external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), []);
        $queue = quiz_grading_helper::get_queue((int)$USER->id);
        debugging(
            'local_btiportal_get_quiz_grading_queue external response count=' . count($queue),
            DEBUG_DEVELOPER
        );
        return $queue;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student user id'),
            'student' => new external_value(PARAM_TEXT, 'Student name'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'course' => new external_value(PARAM_TEXT, 'Course name'),
            'assessment' => new external_value(PARAM_TEXT, 'Quiz name'),
            'assessmenttype' => new external_value(PARAM_ALPHANUMEXT, 'BTI assessment type'),
            'unitid' => new external_value(PARAM_INT, 'Unit id; zero for Final Exams'),
            'attemptnumber' => new external_value(PARAM_INT, 'Attempt number'),
            'submitted' => new external_value(PARAM_INT, 'Submission timestamp'),
            'attemptsumgrades' => new external_value(PARAM_FLOAT, 'Raw attempt total; -1 if unavailable'),
            'overallgrade' => new external_value(PARAM_FLOAT, 'Overall quiz grade; -1 if unavailable'),
            'questioncount' => new external_value(PARAM_INT, 'Question count'),
            'pendingcount' => new external_value(PARAM_INT, 'Questions needing manual grading'),
            'autogradedcount' => new external_value(PARAM_INT, 'Automatically graded questions'),
            'manuallygradedcount' => new external_value(PARAM_INT, 'Manually graded questions'),
            'inprogresscount' => new external_value(PARAM_INT, 'Questions still in progress'),
        ]));
    }
}
