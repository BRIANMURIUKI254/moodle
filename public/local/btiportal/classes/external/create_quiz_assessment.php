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
 * Create a Moodle quiz assessment for a taught unit or an assigned course Final Exam.
 */
class create_quiz_assessment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id (course_sections.id); 0 for Final Exams'),
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
        global $DB, $USER;

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

        $courseid = (int)$params['courseid'];
        $course = $courseid > SITEID
            ? $DB->get_record('course', ['id' => $courseid], 'id', IGNORE_MISSING)
            : false;
        if (!$course) {
            error_log(sprintf(
                '[local_btiportal] Quiz assessment rejected: invalid course id; user=%d course=%d unit=%d type=%s',
                (int)$USER->id,
                $courseid,
                (int)$params['unitid'],
                (string)$params['assessmenttype']
            ));
            throw new \moodle_exception('invalidcourseid', 'local_btiportal');
        }

        $context = \context_course::instance($courseid);
        $assessmenttype = assessment_helper::normalize_type((string)$params['assessmenttype']);
        if ($assessmenttype === assessment_helper::TYPE_FINAL) {
            $listedcourse = null;
            foreach (lecturer_teaching_helper::list_moodle_teaching_courses((int)$USER->id) as $course) {
                if ((int)$course['courseid'] === $courseid) {
                    $listedcourse = $course;
                    break;
                }
            }
            error_log(sprintf(
                '[local_btiportal] Final Exam authorization probe: endpoint=course_teacher_v2 user=%d submittedcourseid=%d unitid=%d courseexists=1 actualcourseid=%d selector_match=%d selector_status=%s helper=%s manageactivities=%d questionadd=%d',
                (int)$USER->id,
                $courseid,
                (int)$params['unitid'],
                (int)$course->id,
                $listedcourse ? 1 : 0,
                $listedcourse ? (string)$listedcourse['statuslabel'] : 'not_listed',
                'lecturer_teaching_helper::user_teaches_moodle_course',
                has_capability('moodle/course:manageactivities', $context) ? 1 : 0,
                has_capability('moodle/question:add', $context) ? 1 : 0
            ));
        }
        self::validate_context($context);
        if ($assessmenttype === assessment_helper::TYPE_FINAL) {
            if ((int)$params['unitid'] !== 0) {
                throw new \invalid_parameter_exception('Final exams must not be associated with a course unit');
            }
            try {
                lecturer_teaching_helper::require_moodle_course_teacher((int)$USER->id, $courseid);
            } catch (\moodle_exception $exception) {
                error_log(sprintf(
                    '[local_btiportal] Final Exam denied: teacher is not assigned to Moodle course; user=%d course=%d',
                    (int)$USER->id,
                    $courseid
                ));
                throw $exception;
            }
            error_log(sprintf(
                '[local_btiportal] Final Exam authorization probe: outcome=authorized user=%d course=%d unit=%d helper=%s',
                (int)$USER->id,
                $courseid,
                (int)$params['unitid'],
                'lecturer_teaching_helper::user_teaches_moodle_course'
            ));
        } else {
            if ((int)$params['unitid'] <= 0) {
                throw new \invalid_parameter_exception('A course unit is required for this assessment type');
            }
            gradebook_helper::require_unit_teacher(
                (int)$USER->id,
                $courseid,
                (int)$params['unitid']
            );
            lecturer_teaching_helper::ensure_editing_teacher_for_assigned_course(
                (int)$USER->id,
                $courseid
            );
        }
        try {
            assessment_helper::require_quiz_creation_capabilities($context);
        } catch (\moodle_exception $exception) {
            error_log(sprintf(
                '[local_btiportal] Quiz assessment denied: missing quiz capability; user=%d course=%d type=%s',
                (int)$USER->id,
                $courseid,
                $assessmenttype
            ));
            throw $exception;
        }

        $decoded = json_decode((string)$params['questionsjson'], true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception('questionsjson must be a JSON array');
        }

        try {
            return assessment_helper::create_quiz_assessment(
                $courseid,
                (int)$params['unitid'],
                (string)$params['name'],
                (string)$params['intro'],
                $assessmenttype,
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
        } catch (\moodle_exception $exception) {
            error_log(sprintf(
                '[local_btiportal] Quiz creation failed: user=%d course=%d type=%s errorcode=%s',
                (int)$USER->id,
                $courseid,
                $assessmenttype,
                $exception->errorcode
            ));
            throw $exception;
        }
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
