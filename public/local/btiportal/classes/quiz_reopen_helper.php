<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

use mod_quiz\local\override_manager;
use mod_quiz\quiz_settings;

/**
 * Reopen a quiz for an individual student and apply their retake grading policy.
 */
class quiz_reopen_helper {
    private const LATEST_GRADE_REASON = 'BTI portal: latest attempt grade applies to this student.';

    /**
     * Return the effective exam settings for one student.
     *
     * @return array<string,int|string>
     */
    public static function get_student_settings(
        int $quizid,
        int $courseid,
        int $studentid,
        int $teacherid
    ): array {
        global $DB;

        [$quiz, $cm, $meta] = self::load_authorized_quiz($quizid, $courseid, $studentid, $teacherid);
        $override = $DB->get_record('quiz_overrides', [
            'quiz' => $quizid,
            'userid' => $studentid,
        ]) ?: null;
        $policy = $DB->get_record('local_btiportal_quiz_user', [
            'quizid' => $quizid,
            'userid' => $studentid,
        ]);
        $latestattempt = $DB->get_record_sql(
            "SELECT id, attempt
               FROM {quiz_attempts}
              WHERE quiz = :quizid AND userid = :userid AND preview = 0
           ORDER BY attempt DESC, id DESC",
            ['quizid' => $quizid, 'userid' => $studentid],
            IGNORE_MULTIPLE
        );
        $flag = $latestattempt
            ? $DB->get_record('local_btiportal_exam_flag', ['attemptid' => $latestattempt->id])
            : false;

        return [
            'quizid' => $quizid,
            'cmid' => (int)$cm->id,
            'userid' => $studentid,
            'latestattemptnumber' => $latestattempt ? (int)$latestattempt->attempt : 0,
            'timeopen' => self::override_value($override, 'timeopen', (int)$quiz->timeopen),
            'timeclose' => self::override_value($override, 'timeclose', (int)$quiz->timeclose),
            'timelimit' => self::override_value($override, 'timelimit', (int)$quiz->timelimit),
            'attempts' => self::override_value($override, 'attempts', (int)$quiz->attempts),
            'requirefullscreen' => $policy
                ? (int)$policy->requirefullscreen
                : (int)($meta->requirefullscreen ?? 0),
            'maxwarnings' => $policy
                ? (int)$policy->maxwarnings
                : max(1, (int)($meta->maxwarnings ?? 3)),
            'warningaction' => $policy && $policy->warningaction !== ''
                ? (string)$policy->warningaction
                : (string)($meta->warningaction ?? assessment_helper::ACTION_FLAG),
            'flagged' => $flag
                ? (int)$flag->flagged
                : ($policy ? (int)$policy->flagged : 0),
            'warningcount' => $flag ? (int)$flag->warningcount : 0,
            'latestgrade' => $policy ? (int)$policy->latestgrade : 0,
        ];
    }

    /**
     * Save a per-student Moodle override and BTI integrity settings.
     *
     * @param array<string,int|string> $input
     * @return array<string,int|string>
     */
    public static function reopen_for_student(array $input, int $teacherid): array {
        global $DB;

        $quizid = (int)$input['quizid'];
        $courseid = (int)$input['courseid'];
        $studentid = (int)$input['userid'];
        [$quiz, $cm] = self::load_authorized_quiz($quizid, $courseid, $studentid, $teacherid);

        $timeopen = (int)$input['timeopen'];
        $timeclose = (int)$input['timeclose'];
        $timelimit = (int)$input['timelimit'];
        $attempts = (int)$input['attempts'];
        $requirefullscreen = (int)$input['requirefullscreen'];
        $maxwarnings = (int)$input['maxwarnings'];
        $warningaction = (string)$input['warningaction'];
        $flagged = (int)$input['flagged'];
        $warningcount = (int)$input['warningcount'];

        if ($timeopen < 0 || $timeclose <= $timeopen || $timeclose <= time()) {
            throw new \invalid_parameter_exception('The submission deadline must be in the future and after the opening time.');
        }
        if ($timelimit < 0 || $attempts < 0 || $maxwarnings < 1 || $warningcount < 0
                || !in_array($requirefullscreen, [0, 1], true)
                || !in_array($flagged, [0, 1], true)
                || !in_array($warningaction, assessment_helper::allowed_actions(), true)) {
            throw new \invalid_parameter_exception('One or more exam override values are invalid.');
        }

        $settings = quiz_settings::create($quizid);
        $basequiz = $settings->get_quiz();
        $context = \context_module::instance((int)$cm->id);
        if (!has_capability('mod/quiz:manageoverrides', $context, $teacherid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'manage quiz overrides');
        }

        $latestattempt = $DB->get_record_sql(
            "SELECT id, attempt
               FROM {quiz_attempts}
              WHERE quiz = :quizid AND userid = :userid AND preview = 0
           ORDER BY attempt DESC, id DESC",
            ['quizid' => $quizid, 'userid' => $studentid],
            IGNORE_MULTIPLE
        );
        if ($attempts > 0 && $latestattempt && $attempts <= (int)$latestattempt->attempt) {
            throw new \invalid_parameter_exception('The maximum attempts must allow at least one more attempt.');
        }

        $existing = $DB->get_record('quiz_overrides', [
            'quiz' => $quizid,
            'userid' => $studentid,
        ]);
        $overridefields = [
            'quiz' => $quizid,
            'userid' => $studentid,
            'timeopen' => $timeopen,
            'timeclose' => $timeclose,
            'timelimit' => $timelimit,
            'attempts' => $attempts,
            'reason' => self::LATEST_GRADE_REASON,
            'reasonformat' => FORMAT_PLAIN,
        ];
        if ($existing) {
            $overridefields['id'] = (int)$existing->id;
        }
        $hascoreoverride = $timeopen !== (int)$quiz->timeopen
            || $timeclose !== (int)$quiz->timeclose
            || $timelimit !== (int)$quiz->timelimit
            || $attempts !== (int)$quiz->attempts;
        if (($hascoreoverride || $existing) && class_exists(override_manager::class)) {
            $override = new override_manager($basequiz, $context);
            $override->save_override($overridefields);
        } else if ($hascoreoverride || $existing) {
            $record = (object)[
                'quiz' => $quizid,
                'userid' => $studentid,
                'groupid' => null,
                'timeopen' => $timeopen === (int)$quiz->timeopen ? null : $timeopen,
                'timeclose' => $timeclose === (int)$quiz->timeclose ? null : $timeclose,
                'timelimit' => $timelimit === (int)$quiz->timelimit ? null : $timelimit,
                'attempts' => $attempts === (int)$quiz->attempts ? null : $attempts,
                'reason' => self::LATEST_GRADE_REASON,
                'reasonformat' => FORMAT_PLAIN,
            ];
            if ($existing) {
                $record->id = (int)$existing->id;
                $DB->update_record('quiz_overrides', $record);
            } else if ($hascoreoverride) {
                $DB->insert_record('quiz_overrides', $record);
            }
            quiz_update_open_attempts(['quizid' => $quizid]);
            quiz_update_events($basequiz, $record);
        }

        $now = time();
        $policy = $DB->get_record('local_btiportal_quiz_user', [
            'quizid' => $quizid,
            'userid' => $studentid,
        ]);
        if ($policy) {
            $policy->requirefullscreen = $requirefullscreen;
            $policy->maxwarnings = $maxwarnings;
            $policy->warningaction = $warningaction;
            $policy->latestgrade = 1;
            $policy->flagged = $flagged;
            $policy->gradefromattempt = $latestattempt ? (int)$latestattempt->attempt : 0;
            $policy->timemodified = $now;
            $DB->update_record('local_btiportal_quiz_user', $policy);
        } else {
            $DB->insert_record('local_btiportal_quiz_user', (object)[
                'quizid' => $quizid,
                'userid' => $studentid,
                'requirefullscreen' => $requirefullscreen,
                'maxwarnings' => $maxwarnings,
                'warningaction' => $warningaction,
                'latestgrade' => 1,
                'flagged' => $flagged,
                'gradefromattempt' => $latestattempt ? (int)$latestattempt->attempt : 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        if ($latestattempt) {
            $flag = $DB->get_record('local_btiportal_exam_flag', ['attemptid' => $latestattempt->id]);
            if ($flag) {
                $flag->flagged = $flagged;
                $flag->warningcount = $warningcount;
                $flag->timemodified = $now;
                $DB->update_record('local_btiportal_exam_flag', $flag);
            } else if ($flagged || $warningcount > 0) {
                $DB->insert_record('local_btiportal_exam_flag', (object)[
                    'quizid' => $quizid,
                    'attemptid' => (int)$latestattempt->id,
                    'userid' => $studentid,
                    'warningcount' => $warningcount,
                    'flagged' => $flagged,
                    'autosubmitted' => 0,
                    'lastaction' => 'teacher_override',
                    'timemodified' => $now,
                ]);
            }
        }

        self::apply_latest_attempt_grade($quizid, $studentid);
        return self::get_student_settings($quizid, $courseid, $studentid, $teacherid);
    }

    /**
     * If enabled for this student, make their latest finished attempt their quiz grade.
     */
    public static function apply_latest_attempt_grade(int $quizid, int $userid): void {
        global $DB;

        $policy = $DB->get_record('local_btiportal_quiz_user', [
            'quizid' => $quizid,
            'userid' => $userid,
            'latestgrade' => 1,
        ]);
        if (!$policy) {
            return;
        }
        $attempt = $DB->get_record_sql(
            "SELECT *
               FROM {quiz_attempts}
              WHERE quiz = :quizid AND userid = :userid AND preview = 0 AND state = :finished
           ORDER BY attempt DESC, id DESC",
            ['quizid' => $quizid, 'userid' => $userid, 'finished' => \mod_quiz\quiz_attempt::FINISHED],
            IGNORE_MULTIPLE
        );
        if (!$attempt || (int)$attempt->attempt <= (int)$policy->gradefromattempt) {
            return;
        }

        $usage = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);
        foreach ($usage->get_slots() as $slot) {
            $state = $usage->get_question_state($slot)->get_summary_state();
            if (in_array($state, ['needsgrading', 'inprogress'], true)) {
                return;
            }
        }

        quiz_settings::create($quizid)
            ->get_grade_calculator()
            ->recompute_final_grade($userid, [$attempt]);
    }

    /**
     * Apply the latest-grade rule after Moodle has finished grading an attempt.
     */
    public static function attempt_graded(\mod_quiz\event\attempt_graded $event): void {
        global $DB;
        $attempt = $DB->get_record('quiz_attempts', ['id' => $event->objectid], 'quiz, userid');
        if ($attempt) {
            self::apply_latest_attempt_grade((int)$attempt->quiz, (int)$attempt->userid);
        }
    }

    /**
     * Reapply the latest-grade rule after a manual question grade is saved.
     */
    public static function question_manually_graded(
        \mod_quiz\event\question_manually_graded $event
    ): void {
        global $DB;
        $eventdata = $event->get_data();
        $attemptid = (int)($eventdata['other']['attemptid'] ?? 0);
        if ($attemptid <= 0) {
            return;
        }
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'quiz, userid');
        if ($attempt) {
            self::apply_latest_attempt_grade((int)$attempt->quiz, (int)$attempt->userid);
        }
    }

    /**
     * @return array{0:object,1:object,2:object|false}
     */
    private static function load_authorized_quiz(
        int $quizid,
        int $courseid,
        int $studentid,
        int $teacherid
    ): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid, 'course' => $courseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        $meta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cm->id]);

        assessment_helper::require_can_manage($teacherid, $courseid, (int)$cm->section, (int)$cm->id);
        if (!is_enrolled($context, $studentid, 'mod/quiz:attempt')) {
            throw new \moodle_exception('usernotenrolled', 'error');
        }

        return [$quiz, $cm, $meta];
    }

    private static function override_value(?object $override, string $field, int $default): int {
        return $override && $override->$field !== null ? (int)$override->$field : $default;
    }
}
