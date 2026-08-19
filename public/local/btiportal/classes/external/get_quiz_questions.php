<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;

class get_quiz_questions extends external_api {
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
        return [
            'questions' => assessment_helper::get_quiz_questions_for_teacher((int)$params['cmid'], (int)$USER->id),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Slot'),
                'page' => new external_value(PARAM_INT, 'Page'),
                'questionid' => new external_value(PARAM_INT, 'Question id'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'qtype'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'questiontext' => new external_value(PARAM_RAW, 'Stem HTML'),
                'maxmark' => new external_value(PARAM_FLOAT, 'Marks'),
                'options' => new external_multiple_structure(new external_single_structure([
                    'label' => new external_value(PARAM_RAW, 'Option HTML'),
                    'fraction' => new external_value(PARAM_FLOAT, 'Fraction'),
                    'iscorrect' => new external_value(PARAM_INT, 'Marked correct'),
                ])),
                'expectedanswer' => new external_value(PARAM_RAW, 'Expected answer text'),
                'generalfeedback' => new external_value(PARAM_RAW, 'Explanation'),
            ])),
        ]);
    }
}
