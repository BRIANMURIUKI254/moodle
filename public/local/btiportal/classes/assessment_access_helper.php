<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Single student-access gate for portal assessments (quiz, CAT, exam, assignment).
 *
 * Throws typed moodle_exception errorcodes so the portal can render a precise screen.
 * Legacy codes studentnotenrolled / quizaccessdenied are never thrown.
 */
class assessment_access_helper {
    public const OK = 'ok';
    public const NOT_ENROLLED = 'not_enrolled';
    public const ENROL_SUSPENDED = 'enrol_suspended';
    public const ENROL_EXPIRED = 'enrol_expired';
    public const NOT_YET_OPEN = 'not_yet_open';
    public const CLOSED = 'closed';
    public const RESTRICTED = 'restricted';
    public const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const PASSWORD_REQUIRED = 'password_required';
    public const ACTIVITY_HIDDEN = 'activity_hidden';

    /** @var array<string,mixed> */
    private static array $denycontext = [];

    /**
     * @return array{code:string,cmid:int,courseid:int,module:string,instanceid:int,selfhealed:int,pluginversion:string,pluginrelease:string,gitsha:string}
     */
    public static function assert_can_access_assessment(
        int $userid,
        int $cmid,
        bool $selfheal = false
    ): array {
        global $DB;

        self::$denycontext = [
            'userid' => $userid,
            'cmid' => $cmid,
            'courseid' => 0,
            'selfheal_requested' => $selfheal ? 1 : 0,
        ];

        if ($userid <= 0 || $cmid <= 0) {
            self::deny(self::RESTRICTED, 'The assessment could not be identified.', 'invalid_ids');
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            self::deny(self::ACTIVITY_HIDDEN, 'This assessment is not available.', 'missing_cm');
        }

        self::$denycontext['courseid'] = (int)$cm->course;
        self::$denycontext['module'] = (string)$cm->modname;

        $course = get_course((int)$cm->course);
        if ((int)$course->visible !== 1) {
            self::deny(self::ACTIVITY_HIDDEN, 'This course is unavailable.', 'course_hidden');
        }
        if (!empty($cm->deletioninprogress) || (int)$cm->visible !== 1) {
            $authorizedfinal = self::is_authorized_programme_final($userid, $cm);
            if (!$authorizedfinal) {
                self::deny(self::ACTIVITY_HIDDEN, 'This assessment is hidden.', 'cm_hidden');
            }
        }

        $selfhealed = 0;
        self::assert_enrolment((int)$course->id, $userid, $cm, $selfheal, $selfhealed);

        $modcontext = \context_module::instance((int)$cm->id);
        if ($cm->modname === 'quiz') {
            if (!has_capability('mod/quiz:attempt', $modcontext, $userid)) {
                self::deny(self::RESTRICTED, 'You do not have permission to attempt this assessment.', 'no_attempt_cap');
            }
            self::assert_quiz_rules($cm, $userid);
        } else if ($cm->modname === 'assign') {
            if (!has_capability('mod/assign:submit', $modcontext, $userid)) {
                self::deny(self::RESTRICTED, 'You do not have permission to submit this assignment.', 'no_submit_cap');
            }
            self::assert_assign_window((int)$cm->instance);
        } else {
            self::deny(self::RESTRICTED, 'This activity type cannot be opened in the portal.', 'unsupported_module');
        }

        return array_merge([
            'code' => self::OK,
            'cmid' => (int)$cm->id,
            'courseid' => (int)$cm->course,
            'module' => (string)$cm->modname,
            'instanceid' => (int)$cm->instance,
            'selfhealed' => $selfhealed,
        ], plugin_build_helper::marker());
    }

    /**
     * Enrolment check with optional self-heal for an entitled programme student.
     */
    private static function assert_enrolment(
        int $courseid,
        int $userid,
        object $cm,
        bool $selfheal,
        int &$selfhealed
    ): void {
        global $DB;

        $now = time();
        $rows = $DB->get_records_sql(
            "SELECT ue.status, ue.timestart, ue.timeend, e.status AS enrolstatus
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid AND e.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );

        $suspended = false;
        $expired = false;
        $active = false;
        foreach ($rows as $row) {
            if ((int)$row->enrolstatus !== 0) {
                continue;
            }
            if ((int)$row->status !== 0) {
                $suspended = true;
                continue;
            }
            if (!empty($row->timestart) && (int)$row->timestart > $now) {
                continue;
            }
            if (!empty($row->timeend) && (int)$row->timeend < $now) {
                $expired = true;
                continue;
            }
            $active = true;
        }

        $context = \context_course::instance($courseid);
        if ($active && is_enrolled($context, $userid, '', true)) {
            return;
        }
        if ($suspended && !$active) {
            self::deny(self::ENROL_SUSPENDED, null, 'enrol_row_suspended');
        }
        if ($expired && !$active) {
            self::deny(self::ENROL_EXPIRED, null, 'enrol_row_expired');
        }

        $entitled = student_assessment_entitlement_helper::is_entitled($userid, $courseid);
        if ($selfheal && $entitled) {
            $result = enrolment_transition_helper::ensure_student_enrolment($userid, $courseid);
            self::$denycontext['selfheal_ran'] = 1;
            self::$denycontext['selfheal_ok'] = !empty($result['ok']) ? 1 : 0;
            self::$denycontext['selfheal_message'] = (string)($result['message'] ?? '');
            if (!empty($result['ok']) && is_enrolled($context, $userid, '', true)) {
                $selfhealed = 1;
                return;
            }
            self::deny(self::NOT_ENROLLED, null, 'selfheal_failed');
        }

        self::$denycontext['selfheal_ran'] = 0;
        if (!$selfheal) {
            self::$denycontext['selfheal_skip_reason'] = 'read_only_preflight';
        } else if (!$entitled) {
            self::$denycontext['selfheal_skip_reason'] = 'course_not_entitled';
        } else {
            self::$denycontext['selfheal_skip_reason'] = 'unknown';
        }

        self::deny(self::NOT_ENROLLED, null, 'not_enrolled');
    }

    private static function assert_quiz_rules(object $cm, int $userid): void {
        $quizobj = quiz_settings::create((int)$cm->instance, $userid);
        if (!$quizobj->has_questions()) {
            self::deny(self::RESTRICTED, 'This quiz has no questions available.', 'no_questions');
        }

        $quiz = $quizobj->get_quiz();
        $now = time();
        $timeopen = (int)$quiz->timeopen;
        $timeclose = (int)$quiz->timeclose;
        if ($timeopen > 0 && $now < $timeopen) {
            self::deny(self::NOT_YET_OPEN, null, 'before_open');
        }
        if ($timeclose > 0 && $now >= $timeclose) {
            self::deny(self::CLOSED, null, 'after_close');
        }

        $accessmanager = $quizobj->get_access_manager($now);
        $preventreasons = $accessmanager->prevent_access();
        if (!empty($preventreasons)) {
            $text = implode(' ', array_map(static function ($reason) {
                return trim(strip_tags((string)$reason));
            }, $preventreasons));
            $lower = strtolower($text);
            if (str_contains($lower, 'password')) {
                self::deny(self::PASSWORD_REQUIRED, $text, 'password');
            }
            if (str_contains($lower, 'attempt')) {
                self::deny(self::ATTEMPTS_EXHAUSTED, $text, 'attempts');
            }
            if (str_contains($lower, 'not currently available') || str_contains($lower, 'not available')) {
                if ($timeopen > 0 && $now < $timeopen) {
                    self::deny(self::NOT_YET_OPEN, $text, 'prevent_before_open');
                }
                if ($timeclose > 0 && $now >= $timeclose) {
                    self::deny(self::CLOSED, $text, 'prevent_after_close');
                }
            }
            self::deny(self::RESTRICTED, $text !== '' ? $text : null, 'quiz_prevent_access');
        }

        list($currentattemptid, $attemptnumber, $lastattempt, $messages) =
            quiz_validate_new_attempt($quizobj, $accessmanager, false, -1, false);
        if (!empty($messages)) {
            $text = implode(' ', array_map(static function ($message) {
                return trim(strip_tags((string)$message));
            }, $messages));
            $lower = strtolower($text);
            if (str_contains($lower, 'attempt')) {
                self::deny(self::ATTEMPTS_EXHAUSTED, $text, 'validate_attempts');
            }
            self::deny(self::RESTRICTED, $text !== '' ? $text : null, 'validate_messages');
        }

        if ($currentattemptid && $lastattempt && (string)$lastattempt->state === quiz_attempt::OVERDUE) {
            self::deny(self::CLOSED, 'This attempt is overdue and can no longer be continued.', 'overdue');
        }
    }

    private static function assert_assign_window(int $assignid): void {
        global $DB;
        $assign = $DB->get_record('assign', ['id' => $assignid], '*', IGNORE_MISSING);
        if (!$assign) {
            self::deny(self::ACTIVITY_HIDDEN, null, 'missing_assign');
        }
        $now = time();
        $from = (int)$assign->allowsubmissionsfromdate;
        $cutoff = (int)$assign->cutoffdate;
        $due = (int)$assign->duedate;
        if ($from > 0 && $now < $from) {
            self::deny(self::NOT_YET_OPEN, null, 'assign_before_open');
        }
        $end = $cutoff > 0 ? $cutoff : $due;
        if ($end > 0 && $now >= $end) {
            self::deny(self::CLOSED, null, 'assign_closed');
        }
    }

    public static function is_authorized_programme_student(int $userid, int $courseid): bool {
        return student_assessment_entitlement_helper::is_entitled($userid, $courseid);
    }

    private static function is_authorized_programme_final(int $userid, object $cm): bool {
        global $DB;
        if ($cm->modname !== 'quiz') {
            return false;
        }
        $meta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => (int)$cm->id]);
        if (!$meta
            || (int)$meta->unitid !== 0
            || strtolower(trim((string)$meta->assessmenttype)) !== 'final'
        ) {
            return false;
        }
        $kind = student_assessment_entitlement_helper::classify_course($userid, (int)$cm->course)['kind'];
        return $kind === student_assessment_entitlement_helper::KIND_PROGRAMME_CONTAINER;
    }

    /**
     * Map any leftover legacy Moodle/plugin codes onto the typed set.
     */
    public static function typed_code(string $code): string {
        return match ($code) {
            'studentnotenrolled', 'notenrolled' => self::NOT_ENROLLED,
            'quizaccessdenied', 'quizdifferentcourse', 'quizinvalidcm', 'quiznoquestions' => self::RESTRICTED,
            'enrolmentsuspended' => self::ENROL_SUSPENDED,
            'enrolmentexpired' => self::ENROL_EXPIRED,
            'courseunavailable' => self::ACTIVITY_HIDDEN,
            default => $code,
        };
    }

    /**
     * Re-throw with a typed local_btiportal code only.
     */
    public static function rethrow_typed(\Throwable $exception): void {
        if ($exception instanceof \moodle_exception) {
            $typed = self::typed_code((string)$exception->errorcode);
            if ($typed !== (string)$exception->errorcode
                || $exception->errorcode === 'studentnotenrolled'
                || $exception->errorcode === 'quizaccessdenied'
            ) {
                self::deny($typed, $exception->a ? (string)$exception->a : $exception->getMessage(), 'legacy_alias:' . $exception->errorcode);
            }
        }
        throw $exception;
    }

    /**
     * @return never
     */
    private static function deny(string $code, $detail = null, string $why = ''): void {
        $code = self::typed_code($code);
        $build = plugin_build_helper::marker();
        $userid = (int)(self::$denycontext['userid'] ?? 0);
        $courseid = (int)(self::$denycontext['courseid'] ?? 0);
        $classification = $userid > 0 && $courseid > 0
            ? student_assessment_entitlement_helper::classify_course($userid, $courseid)
            : [
                'kind' => student_assessment_entitlement_helper::KIND_NEITHER,
                'entitled' => 0,
                'is_programme_container' => 0,
                'is_current_semester_unit' => 0,
                'programme_code' => '',
                'course_shortname' => '',
                'unitcode' => '',
            ];

        $payload = array_merge(self::$denycontext, $classification, $build, [
            'code' => $code,
            'why' => $why,
            'selfheal_ran' => (int)(self::$denycontext['selfheal_ran'] ?? 0),
            'selfheal_skip_reason' => (string)(self::$denycontext['selfheal_skip_reason'] ?? ''),
        ]);
        error_log('local_btiportal assessment_deny ' . json_encode($payload));
        debugging('local_btiportal assessment_deny ' . json_encode($payload), DEBUG_NORMAL);

        $detailtext = is_string($detail) ? $detail : '';
        if (is_object($detail) && isset($detail->detail)) {
            $detailtext = (string)$detail->detail;
        }
        $a = trim($detailtext . ' [build ' . $build['pluginversion'] . '/' . $build['gitsha'] . ']');
        throw new \moodle_exception($code, 'local_btiportal', '', $a, json_encode($build));
    }
}
