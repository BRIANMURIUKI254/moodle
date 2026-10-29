<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;

class get_my_final_exam_attempt_status extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Moodle quiz instance id'),
        ]);
    }

    public static function execute(int $quizid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), compact('quizid'));
        $quizid = (int)$params['quizid'];
        if ($quizid <= 0) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('quizid', 'A valid quiz id is required')
            );
        }

        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $profile = $DB->get_record(
            'local_btiportal_student_profile',
            ['userid' => $userid],
            'userid,status,admissionstatus,academicstatus,programmeid',
            IGNORE_MISSING
        );
        if (!$profile
            || strtolower(trim((string)$profile->status)) !== 'active'
            || strtolower(trim((string)$profile->admissionstatus)) !== 'active'
            || strtolower(trim((string)$profile->academicstatus)) !== 'active'
            || (int)$profile->programmeid <= 0
        ) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('userid', 'Active student academic profile required')
            );
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id,course', IGNORE_MISSING);
        if (!$quiz || (int)$quiz->course <= 1) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('quizid', 'Quiz not found')
            );
        }

        $course = $DB->get_record(
            'course',
            ['id' => (int)$quiz->course],
            'id,shortname,visible',
            IGNORE_MISSING
        );
        if (!$course || (int)$course->visible !== 1) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('quizid', 'Quiz course is not visible')
            );
        }

        $programme = $DB->get_record(
            'local_btiportal_programme',
            ['id' => (int)$profile->programmeid],
            'id,code',
            IGNORE_MISSING
        );
        if (!$programme
            || trim((string)$programme->code) === ''
            || trim((string)$programme->code) !== trim((string)$course->shortname)
        ) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('quizid', 'Quiz is outside the student programme')
            );
        }

        $cm = get_coursemodule_from_instance(
            'quiz',
            $quizid,
            (int)$course->id,
            false,
            false
        );
        if (!$cm || (int)$cm->visible !== 1) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('quizid', 'Quiz activity is not visible')
            );
        }

        $meta = $DB->get_record(
            'local_btiportal_assessment_meta',
            [
                'cmid' => (int)$cm->id,
                'courseid' => (int)$course->id,
                'unitid' => 0,
                'module' => 'quiz',
                'instanceid' => $quizid,
                'assessmenttype' => 'final',
            ],
            'id,cmid,courseid,unitid,module,instanceid,assessmenttype',
            IGNORE_MISSING
        );
        if (!$meta) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('quizid', 'Quiz is not an authorized programme Final Exam')
            );
        }

        $submitted = $DB->record_exists_sql(
            'SELECT 1
               FROM {quiz_attempts}
              WHERE quiz = ?
                AND userid = ?
                AND preview = 0
                AND state = ?
                AND timefinish > 0',
            [$quizid, $userid, 'finished']
        );

        return academic_api_response::for_external(academic_api_response::ok([
            'submitted' => (bool)$submitted,
        ]));
    }

    public static function execute_returns(): external_single_structure {
        return academic_api_response::returns_structure([
            'submitted' => new external_value(PARAM_BOOL, 'Whether a finished attempt exists'),
        ]);
    }
}
