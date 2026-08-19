<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;
use local_btiportal\gradebook_helper;
use local_btiportal\lecturer_teaching_helper;

/**
 * Create a Moodle quiz assessment with questions for a unit the teacher manages.
 */
class create_quiz_assessment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id (course_sections.id)'),
            'name' => new external_value(PARAM_TEXT, 'Assessment title'),
            'intro' => new external_value(PARAM_RAW, 'Instructions HTML', VALUE_DEFAULT, ''),
            'assessmenttype' => new external_value(PARAM_ALPHANUMEXT, 'quiz, cat, midterm, final, test, practical'),
            'grademax' => new external_value(PARAM_FLOAT, 'Total marks', VALUE_DEFAULT, 100),
            'passmark' => new external_value(PARAM_FLOAT, 'Pass mark', VALUE_DEFAULT, 0),
            'timeopen' => new external_value(PARAM_INT, 'Unix start time', VALUE_DEFAULT, 0),
            'timeclose' => new external_value(PARAM_INT, 'Unix end time', VALUE_DEFAULT, 0),
            'timelimit' => new external_value(PARAM_INT, 'Duration in seconds', VALUE_DEFAULT, 0),
            'maxattempts' => new external_value(PARAM_INT, 'Max attempts (0 = unlimited)', VALUE_DEFAULT, 1),
            'shufflequestions' => new external_value(PARAM_INT, 'Shuffle question order', VALUE_DEFAULT, 0),
            'shuffleanswers' => new external_value(PARAM_INT, 'Shuffle answer options', VALUE_DEFAULT, 0),
            'requirefullscreen' => new external_value(PARAM_INT, 'Require fullscreen', VALUE_DEFAULT, 0),
            'maxwarnings' => new external_value(PARAM_INT, 'Max window-leave warnings', VALUE_DEFAULT, 3),
            'warningaction' => new external_value(PARAM_ALPHANUMEXT, 'flag, submit, or submit_flag', VALUE_DEFAULT, 'submit_flag'),
            'questionsjson' => new external_value(PARAM_RAW, 'JSON array of question objects'),
        ]);
    }

    public static function execute(
        int $courseid,
        int $unitid,
        string $name,
        string $intro,
        string $assessmenttype,
        float $grademax,
        float $passmark,
        int $timeopen,
        int $timeclose,
        int $timelimit,
        int $maxattempts,
        int $shufflequestions,
        int $shuffleanswers,
        int $requirefullscreen,
        int $maxwarnings,
        string $warningaction,
        string $questionsjson
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'name' => $name,
            'intro' => $intro,
            'assessmenttype' => $assessmenttype,
            'grademax' => $grademax,
            'passmark' => $passmark,
            'timeopen' => $timeopen,
            'timeclose' => $timeclose,
            'timelimit' => $timelimit,
            'maxattempts' => $maxattempts,
            'shufflequestions' => $shufflequestions,
            'shuffleanswers' => $shuffleanswers,
            'requirefullscreen' => $requirefullscreen,
            'maxwarnings' => $maxwarnings,
            'warningaction' => $warningaction,
            'questionsjson' => $questionsjson,
        ]);

        $context = \context_course::instance((int)$params['courseid']);
        self::validate_context($context);
        gradebook_helper::require_unit_teacher((int)$USER->id, (int)$params['courseid'], (int)$params['unitid']);
        lecturer_teaching_helper::ensure_editing_teacher_for_assigned_course(
            (int)$USER->id,
            (int)$params['courseid']
        );
        if (!has_capability('moodle/course:manageactivities', $context)
            && !has_capability('mod/quiz:addinstance', $context)
            && !is_siteadmin()
        ) {
            throw new \moodle_exception('teachingpermissioninactive', 'local_btiportal');
        }

        $decoded = json_decode((string)$params['questionsjson'], true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception('questionsjson must be a JSON array');
        }

        return assessment_helper::create_quiz_assessment(
            (int)$params['courseid'],
            (int)$params['unitid'],
            (string)$params['name'],
            (string)$params['intro'],
            (string)$params['assessmenttype'],
            (float)$params['grademax'],
            (float)$params['passmark'],
            (int)$params['timeopen'],
            (int)$params['timeclose'],
            (int)$params['timelimit'],
            (int)$params['maxattempts'],
            (int)$params['shufflequestions'],
            (int)$params['shuffleanswers'],
            (int)$params['requirefullscreen'],
            (int)$params['maxwarnings'],
            (string)$params['warningaction'],
            $decoded
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id'),
            'quizid' => new external_value(PARAM_INT, 'quiz.id'),
            'cmid' => new external_value(PARAM_INT, 'course_modules.id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'intro' => new external_value(PARAM_RAW, 'Intro'),
            'gradeitemid' => new external_value(PARAM_INT, 'Grade item id'),
            'grademax' => new external_value(PARAM_FLOAT, 'Max grade'),
            'passmark' => new external_value(PARAM_FLOAT, 'Pass mark'),
            'mapped' => new external_value(PARAM_BOOL, 'Mapped to unit gradebook'),
            'assessmenttype' => new external_value(PARAM_ALPHANUMEXT, 'Type'),
            'questioncount' => new external_value(PARAM_INT, 'Questions created'),
            'timeopen' => new external_value(PARAM_INT, 'Start'),
            'timeclose' => new external_value(PARAM_INT, 'End'),
            'timelimit' => new external_value(PARAM_INT, 'Duration seconds'),
            'maxattempts' => new external_value(PARAM_INT, 'Max attempts'),
            'requirefullscreen' => new external_value(PARAM_INT, 'Fullscreen required'),
            'maxwarnings' => new external_value(PARAM_INT, 'Max warnings'),
            'warningaction' => new external_value(PARAM_ALPHANUMEXT, 'Action after max warnings'),
        ]);
    }
}
