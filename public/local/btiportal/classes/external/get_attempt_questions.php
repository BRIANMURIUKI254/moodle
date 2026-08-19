<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;

class get_attempt_questions extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'quiz_attempts.id'),
        ]);
    }

    public static function execute(int $attemptid): array {
        global $USER, $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);
        $quizid = (int)$DB->get_field('quiz_attempts', 'quiz', ['id' => (int)$params['attemptid']], MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quizid, 0, false, MUST_EXIST);
        self::validate_context(\context_module::instance((int)$cm->id));
        return assessment_helper::get_attempt_questions((int)$params['attemptid'], (int)$USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'cmid' => new external_value(PARAM_INT, 'CM id'),
            'userid' => new external_value(PARAM_INT, 'Owner'),
            'state' => new external_value(PARAM_ALPHANUMEXT, 'Attempt state'),
            'timestart' => new external_value(PARAM_INT, 'Start'),
            'endtime' => new external_value(PARAM_INT, 'Due timestamp'),
            'timelimit' => new external_value(PARAM_INT, 'Duration'),
            'timeopen' => new external_value(PARAM_INT, 'Quiz open'),
            'timeclose' => new external_value(PARAM_INT, 'Quiz close'),
            'currentpage' => new external_value(PARAM_INT, 'Page'),
            'requirefullscreen' => new external_value(PARAM_INT, 'Fullscreen'),
            'maxwarnings' => new external_value(PARAM_INT, 'Max warnings'),
            'warningaction' => new external_value(PARAM_ALPHANUMEXT, 'Action'),
            'warningcount' => new external_value(PARAM_INT, 'Current warnings'),
            'flagged' => new external_value(PARAM_INT, 'Flagged'),
            'autosubmitted' => new external_value(PARAM_INT, 'Auto-submitted'),
            'layout' => new external_value(PARAM_RAW, 'Layout'),
            'questions' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Slot'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Type'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'questiontext' => new external_value(PARAM_RAW, 'Stem'),
                'maxmark' => new external_value(PARAM_FLOAT, 'Marks'),
                'status' => new external_value(PARAM_ALPHANUMEXT, 'State class'),
                'answered' => new external_value(PARAM_BOOL, 'Has answer'),
                'flagged' => new external_value(PARAM_INT, 'Question flag'),
                'prefix' => new external_value(PARAM_RAW, 'Field prefix'),
                'sequencecheck' => new external_value(PARAM_INT, 'Sequence check'),
                'sequencecheckname' => new external_value(PARAM_RAW, 'Sequence field name'),
                'options' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Option id'),
                    'label' => new external_value(PARAM_RAW, 'Label HTML'),
                    'value' => new external_value(PARAM_RAW, 'Submit value'),
                    'fieldname' => new external_value(PARAM_RAW, 'POST field'),
                    'selected' => new external_value(PARAM_INT, 'Selected'),
                ])),
                'inputname' => new external_value(PARAM_RAW, 'Primary input name'),
                'inputvalue' => new external_value(PARAM_RAW, 'Current value'),
                'requiresfile' => new external_value(PARAM_INT, 'Needs file'),
            ])),
        ]);
    }
}
