<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;

class get_assessments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Course ids',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(array $courseids = []): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['courseids' => $courseids]);
        $ids = array_values(array_unique(array_map('intval', $params['courseids'])));
        foreach ($ids as $courseid) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if ($context) {
                self::validate_context($context);
            }
        }
        return ['assessments' => assessment_helper::list_for_courses($ids, (int)$USER->id)];
    }

    public static function assessment_structure(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'CM id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Section id'),
            'module' => new external_value(PARAM_ALPHA, 'quiz or assign'),
            'instanceid' => new external_value(PARAM_INT, 'Module instance id'),
            'name' => new external_value(PARAM_TEXT, 'Title'),
            'intro' => new external_value(PARAM_RAW, 'Intro HTML'),
            'assessmenttype' => new external_value(PARAM_ALPHANUMEXT, 'Type'),
            'visible' => new external_value(PARAM_INT, 'Visible'),
            'uservisible' => new external_value(PARAM_INT, 'Visible to this user'),
            'grademax' => new external_value(PARAM_FLOAT, 'Total marks'),
            'passmark' => new external_value(PARAM_FLOAT, 'Pass mark'),
            'questioncount' => new external_value(PARAM_INT, 'Question count'),
            'timeopen' => new external_value(PARAM_INT, 'Available from'),
            'timeclose' => new external_value(PARAM_INT, 'Closes'),
            'timelimit' => new external_value(PARAM_INT, 'Duration seconds'),
            'maxattempts' => new external_value(PARAM_INT, 'Max attempts'),
            'requirefullscreen' => new external_value(PARAM_INT, 'Require fullscreen'),
            'maxwarnings' => new external_value(PARAM_INT, 'Max warnings'),
            'warningaction' => new external_value(PARAM_ALPHANUMEXT, 'Warning action'),
            'section' => new external_value(PARAM_INT, 'Section number'),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assessments' => new external_multiple_structure(self::assessment_structure()),
        ]);
    }
}
