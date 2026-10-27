<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use mod_quiz\quiz_attempt;

/**
 * Persist exam integrity events and warning/flag state in Moodle.
 */
class exam_monitor_helper {
    /** @var list<string> */
    public const EVENT_TYPES = [
        'EXAM_STARTED',
        'TAB_HIDDEN',
        'TAB_VISIBLE',
        'WINDOW_BLUR',
        'WINDOW_FOCUS',
        'FULLSCREEN_ENTER',
        'FULLSCREEN_EXIT',
        'ANSWER_SAVED',
        'TIME_WARNING',
        'EXAM_SUBMITTED',
        'EXAM_AUTO_SUBMITTED',
        'WARNING_ISSUED',
        'ATTEMPT_FLAGGED',
    ];

    public static function normalize_event(string $eventtype): string {
        $e = strtoupper(trim($eventtype));
        if (!in_array($e, self::EVENT_TYPES, true)) {
            throw new \invalid_parameter_exception('Unknown exam event type');
        }
        return $e;
    }

    /**
     * @return array<string,mixed>
     */
    public static function log_event(
        int $userid,
        int $quizid,
        int $attemptid,
        string $eventtype,
        string $details = ''
    ): array {
        global $DB;

        $eventtype = self::normalize_event($eventtype);
        $attempt = self::require_attempt_access($userid, $attemptid, $quizid);
        $cm = get_coursemodule_from_instance('quiz', $attempt->quiz, 0, false, MUST_EXIST);

        $flag = self::get_or_create_flag((int)$attempt->quiz, $attemptid, (int)$attempt->userid);
        $now = time();
        $id = $DB->insert_record('local_btiportal_exam_event', (object)[
            'quizid' => (int)$attempt->quiz,
            'cmid' => (int)$cm->id,
            'courseid' => (int)$cm->course,
            'attemptid' => $attemptid,
            'userid' => (int)$attempt->userid,
            'eventtype' => $eventtype,
            'warningcount' => (int)$flag->warningcount,
            'details' => shorten_text(trim($details), 500, false, ''),
            'timecreated' => $now,
        ]);

        return [
            'id' => (int)$id,
            'eventtype' => $eventtype,
            'warningcount' => (int)$flag->warningcount,
            'flagged' => (int)$flag->flagged,
            'timecreated' => $now,
        ];
    }

    /**
     * Record a confirmed window-leave warning and execute the configured action at the limit.
     *
     * @return array<string,mixed>
     */
    public static function apply_warning(int $userid, int $attemptid, string $reason = 'TAB_HIDDEN'): array {
        global $DB;

        $attemptrec = self::require_attempt_access($userid, $attemptid, 0);
        $attemptobj = quiz_attempt::create($attemptid);
        $cmid = (int)$attemptobj->get_cmid();
        $meta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cmid]);
        $maxwarnings = $meta ? max(1, (int)$meta->maxwarnings) : 3;
        $action = ($meta && !empty($meta->warningaction))
            ? (string)$meta->warningaction
            : assessment_helper::ACTION_SUBMIT_FLAG;

        $flag = self::get_or_create_flag((int)$attemptrec->quiz, $attemptid, (int)$attemptrec->userid);
        $flag->warningcount = (int)$flag->warningcount + 1;
        $flag->timemodified = time();

        $executed = '';
        $submitted = false;
        $atlimit = (int)$flag->warningcount >= $maxwarnings;

        if ($atlimit) {
            if ($action === assessment_helper::ACTION_FLAG || $action === assessment_helper::ACTION_SUBMIT_FLAG) {
                $flag->flagged = 1;
                $flag->lastaction = $action;
                $executed = 'flag';
            }
            if ($action === assessment_helper::ACTION_SUBMIT || $action === assessment_helper::ACTION_SUBMIT_FLAG) {
                $state = $attemptobj->get_state();
                if ($state === quiz_attempt::IN_PROGRESS || $state === quiz_attempt::OVERDUE) {
                    $attemptobj->process_finish(time(), true, null, true);
                    $flag->autosubmitted = 1;
                    $submitted = true;
                    $executed = $executed === 'flag' ? 'submit_flag' : 'submit';
                }
                $flag->lastaction = $action;
            }
        }

        $DB->update_record('local_btiportal_exam_flag', $flag);

        $eventtype = $atlimit ? ($submitted ? 'EXAM_AUTO_SUBMITTED' : 'ATTEMPT_FLAGGED') : 'WARNING_ISSUED';
        self::log_event(
            $userid,
            (int)$attemptrec->quiz,
            $attemptid,
            $eventtype,
            $reason . ' warning ' . $flag->warningcount . '/' . $maxwarnings
        );

        return [
            'attemptid' => $attemptid,
            'warningcount' => (int)$flag->warningcount,
            'maxwarnings' => $maxwarnings,
            'flagged' => (int)$flag->flagged,
            'autosubmitted' => (int)$flag->autosubmitted,
            'submitted' => $submitted ? 1 : 0,
            'action' => $action,
            'executed' => $executed,
            'atlimit' => $atlimit ? 1 : 0,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function list_events(int $cmid, int $userid, int $attemptid = 0): array {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        assessment_helper::require_can_manage(
            $userid,
            (int)$cm->course,
            (int)$cm->section,
            (int)$cm->id
        );

        $params = ['quizid' => (int)$cm->instance];
        $where = 'quizid = :quizid';
        if ($attemptid > 0) {
            $where .= ' AND attemptid = :attemptid';
            $params['attemptid'] = $attemptid;
        }
        $records = $DB->get_records_select(
            'local_btiportal_exam_event',
            $where,
            $params,
            'timecreated DESC, id DESC',
            '*',
            0,
            500
        );
        $out = [];
        foreach ($records as $r) {
            $out[] = [
                'id' => (int)$r->id,
                'quizid' => (int)$r->quizid,
                'cmid' => (int)$r->cmid,
                'attemptid' => (int)$r->attemptid,
                'userid' => (int)$r->userid,
                'eventtype' => (string)$r->eventtype,
                'warningcount' => (int)$r->warningcount,
                'details' => (string)$r->details,
                'timecreated' => (int)$r->timecreated,
            ];
        }
        return $out;
    }

    protected static function get_or_create_flag(int $quizid, int $attemptid, int $userid): object {
        global $DB;
        $existing = $DB->get_record('local_btiportal_exam_flag', ['attemptid' => $attemptid]);
        if ($existing) {
            return $existing;
        }
        $id = $DB->insert_record('local_btiportal_exam_flag', (object)[
            'quizid' => $quizid,
            'attemptid' => $attemptid,
            'userid' => $userid,
            'warningcount' => 0,
            'flagged' => 0,
            'autosubmitted' => 0,
            'lastaction' => '',
            'timemodified' => time(),
        ]);
        return $DB->get_record('local_btiportal_exam_flag', ['id' => $id], '*', MUST_EXIST);
    }

    protected static function require_attempt_access(int $userid, int $attemptid, int $quizid): object {
        global $DB;
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        if ($quizid > 0 && (int)$attempt->quiz !== $quizid) {
            throw new \moodle_exception('nopermissions', 'error', '', 'exam attempt');
        }
        $cm = get_coursemodule_from_instance('quiz', $attempt->quiz, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $isowner = (int)$attempt->userid === $userid;
        $isteacher = has_capability('mod/quiz:viewreports', $context) || is_siteadmin($userid);
        if (!$isowner && !$isteacher) {
            throw new \moodle_exception('nopermissions', 'error', '', 'exam attempt');
        }
        return $attempt;
    }
}
