<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;

class update_assessment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'name' => new external_value(PARAM_TEXT, 'Title', VALUE_DEFAULT, ''),
            'intro' => new external_value(PARAM_RAW, 'Instructions', VALUE_DEFAULT, ''),
            'assessmenttype' => new external_value(PARAM_ALPHANUMEXT, 'Type', VALUE_DEFAULT, ''),
            'grademax' => new external_value(PARAM_FLOAT, 'Total marks', VALUE_DEFAULT, -1),
            'passmark' => new external_value(PARAM_FLOAT, 'Pass mark', VALUE_DEFAULT, -1),
            'timeopen' => new external_value(PARAM_INT, 'Start', VALUE_DEFAULT, -1),
            'timeclose' => new external_value(PARAM_INT, 'End', VALUE_DEFAULT, -1),
            'timelimit' => new external_value(PARAM_INT, 'Duration seconds', VALUE_DEFAULT, -1),
            'maxattempts' => new external_value(PARAM_INT, 'Max attempts', VALUE_DEFAULT, -1),
            'shufflequestions' => new external_value(PARAM_INT, 'Shuffle questions', VALUE_DEFAULT, -1),
            'shuffleanswers' => new external_value(PARAM_INT, 'Shuffle answers', VALUE_DEFAULT, -1),
            'requirefullscreen' => new external_value(PARAM_INT, 'Require fullscreen', VALUE_DEFAULT, -1),
            'maxwarnings' => new external_value(PARAM_INT, 'Max warnings', VALUE_DEFAULT, -1),
            'warningaction' => new external_value(PARAM_ALPHANUMEXT, 'Warning action', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $cmid,
        string $name = '',
        string $intro = '',
        string $assessmenttype = '',
        float $grademax = -1,
        float $passmark = -1,
        int $timeopen = -1,
        int $timeclose = -1,
        int $timelimit = -1,
        int $maxattempts = -1,
        int $shufflequestions = -1,
        int $shuffleanswers = -1,
        int $requirefullscreen = -1,
        int $maxwarnings = -1,
        string $warningaction = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'cmid', 'name', 'intro', 'assessmenttype', 'grademax', 'passmark',
            'timeopen', 'timeclose', 'timelimit', 'maxattempts', 'shufflequestions',
            'shuffleanswers', 'requirefullscreen', 'maxwarnings', 'warningaction'
        ));
        $cm = get_coursemodule_from_id(null, (int)$params['cmid'], 0, false, MUST_EXIST);
        self::validate_context(\context_course::instance((int)$cm->course));

        $fields = [];
        foreach (['name', 'intro', 'assessmenttype', 'warningaction'] as $key) {
            if ($params[$key] !== '') {
                $fields[$key] = $params[$key];
            }
        }
        foreach (['grademax', 'passmark', 'timeopen', 'timeclose', 'timelimit', 'maxattempts',
            'shufflequestions', 'shuffleanswers', 'requirefullscreen', 'maxwarnings'] as $key) {
            if ((float)$params[$key] >= 0) {
                $fields[$key] = $params[$key];
            }
        }
        return assessment_helper::update_quiz_settings((int)$params['cmid'], (int)$USER->id, $fields);
    }

    public static function execute_returns(): external_single_structure {
        return get_assessments::assessment_structure();
    }
}
