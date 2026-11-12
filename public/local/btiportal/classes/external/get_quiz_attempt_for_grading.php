<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\quiz_grading_helper;

class get_quiz_attempt_for_grading extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id'),
            'courseid' => new external_value(PARAM_INT, 'Expected course id'),
            'quizid' => new external_value(PARAM_INT, 'Expected quiz id'),
        ]);
    }

    public static function execute(int $attemptid, int $courseid, int $quizid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'courseid' => $courseid,
            'quizid' => $quizid,
        ]);
        $cm = get_coursemodule_from_instance(
            'quiz',
            (int)$params['quizid'],
            (int)$params['courseid'],
            false,
            MUST_EXIST
        );
        self::validate_context(\context_module::instance((int)$cm->id));
        return quiz_grading_helper::get_attempt_for_grading(
            (int)$params['attemptid'],
            (int)$params['courseid'],
            (int)$params['quizid'],
            (int)$USER->id
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'attemptnumber' => new external_value(PARAM_INT, 'Attempt number'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'student' => new external_value(PARAM_TEXT, 'Student name'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'course' => new external_value(PARAM_TEXT, 'Course name'),
            'assessment' => new external_value(PARAM_TEXT, 'Assessment name'),
            'assessmenttype' => new external_value(PARAM_ALPHANUMEXT, 'BTI assessment type'),
            'unitid' => new external_value(PARAM_INT, 'Unit id; zero for Final Exams'),
            'submitted' => new external_value(PARAM_INT, 'Submission timestamp'),
            'attemptsumgrades' => new external_value(PARAM_FLOAT, 'Raw attempt total; -1 if unavailable'),
            'overallgrade' => new external_value(PARAM_FLOAT, 'Overall quiz grade; -1 if unavailable'),
            'questioncount' => new external_value(PARAM_INT, 'Question count'),
            'pendingcount' => new external_value(PARAM_INT, 'Questions needing manual grading'),
            'autogradedcount' => new external_value(PARAM_INT, 'Automatically graded questions'),
            'manuallygradedcount' => new external_value(PARAM_INT, 'Manually graded questions'),
            'inprogresscount' => new external_value(PARAM_INT, 'Questions still in progress'),
            'gradingstatus' => new external_value(PARAM_ALPHA, 'graded, partial, or untouched', VALUE_DEFAULT, 'untouched'),
            'queuetotal' => new external_value(PARAM_INT, 'Unused on a single attempt', VALUE_DEFAULT, 0),
            'fullygradedcount' => new external_value(PARAM_INT, '1 when this attempt is fully graded', VALUE_DEFAULT, 0),
            'studentcanseemark' => new external_value(PARAM_BOOL, 'Whether Moodle review options currently show marks to the student'),
            'questions' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Question slot'),
                'questionid' => new external_value(PARAM_INT, 'Question id'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Question type'),
                'name' => new external_value(PARAM_TEXT, 'Question name'),
                'questiontext' => new external_value(PARAM_RAW, 'Formatted question text'),
                'response' => new external_value(PARAM_RAW, 'Student response summary'),
                'maxmark' => new external_value(PARAM_FLOAT, 'Maximum mark'),
                'currentmark' => new external_value(PARAM_FLOAT, 'Current mark; -1 when ungraded'),
                'gradingstate' => new external_value(PARAM_ALPHANUMEXT, 'Question-engine summary state'),
                'eligibleformanualgrading' => new external_value(PARAM_BOOL, 'Can be manually graded'),
                'feedback' => new external_value(PARAM_RAW, 'Existing manual feedback'),
                'commentformat' => new external_value(PARAM_INT, 'Moodle comment format', VALUE_DEFAULT, FORMAT_PLAIN),
                'version' => new external_value(PARAM_INT, 'Last question step id; send it back to detect conflicts', VALUE_DEFAULT, 0),
                'files' => new external_multiple_structure(new external_single_structure([
                    'filename' => new external_value(PARAM_FILE, 'File name'),
                    'filesize' => new external_value(PARAM_INT, 'File size in bytes'),
                    'mimetype' => new external_value(PARAM_TEXT, 'MIME type'),
                    'fileurl' => new external_value(PARAM_URL, 'Pluginfile URL; the portal adds the user token'),
                ]), 'Uploaded response files', VALUE_DEFAULT, []),
            ])),
        ]);
    }
}
