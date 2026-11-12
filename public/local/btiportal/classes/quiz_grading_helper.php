<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/questionlib.php');
require_once($CFG->libdir . '/weblib.php');

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Native Moodle quiz-attempt grading for assigned BTI teachers.
 */
class quiz_grading_helper {
    /**
     * List all finished quiz attempts available to the authenticated BTI teacher.
     *
     * @return list<array<string,mixed>>
     */
    public static function get_queue(int $userid, array $filters = []): array {
        global $DB;

        $quizmoduleid = (int)$DB->get_field('modules', 'id', ['name' => 'quiz'], IGNORE_MISSING);
        if ($quizmoduleid <= 0) {
            return [];
        }

        $filtercourseid = (int)($filters['courseid'] ?? 0);
        $filtercmid = (int)($filters['cmid'] ?? 0);
        $filterstatus = strtolower(trim((string)($filters['status'] ?? '')));
        $filterstudent = trim((string)($filters['student'] ?? ''));
        $page = max(0, (int)($filters['page'] ?? 0));
        $perpage = max(0, (int)($filters['perpage'] ?? 0));

        $courseids = $DB->get_fieldset_sql(
            "SELECT courseid FROM {local_btiportal_unit} WHERE teacheruserid = :legacyuserid
             UNION
             SELECT cu.courseid
               FROM {local_btiportal_course_unit_teacher} t
               JOIN {local_btiportal_course_unit} cu ON cu.id = t.courseunitid
              WHERE t.userid = :assigneduserid
                AND t.status = 'active'
                AND cu.status = 'active'",
            ['legacyuserid' => $userid, 'assigneduserid' => $userid]
        );
        foreach (lecturer_teaching_helper::list_moodle_teaching_courses($userid) as $course) {
            $courseids[] = (int)$course['courseid'];
        }
        if (is_siteadmin($userid)) {
            if ($filtercourseid > 0) {
                $courseids[] = $filtercourseid;
            }
            if ($filtercmid > 0) {
                $filteredcourse = $DB->get_field('course_modules', 'course', ['id' => $filtercmid], IGNORE_MISSING);
                if ($filteredcourse) {
                    $courseids[] = (int)$filteredcourse;
                }
            }
        }
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        if (!$courseids) {
            return [];
        }
        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'teacher_course');
        $filtersql = '';
        if ($filtercourseid > 0) {
            $filtersql .= ' AND q.course = :filtercourseid';
            $courseparams['filtercourseid'] = $filtercourseid;
        }
        if ($filtercmid > 0) {
            $filtersql .= ' AND cm.id = :filtercmid';
            $courseparams['filtercmid'] = $filtercmid;
        }
        if ($filterstudent !== '') {
            if (ctype_digit($filterstudent)) {
                $filtersql .= ' AND qa.userid = :filterstudentid';
                $courseparams['filterstudentid'] = (int)$filterstudent;
            } else {
                $filtersql .= ' AND (' . $DB->sql_like('u.firstname', ':filterstudentfirst', false)
                    . ' OR ' . $DB->sql_like('u.lastname', ':filterstudentlast', false)
                    . ' OR ' . $DB->sql_like(
                        $DB->sql_concat('u.firstname', "' '", 'u.lastname'),
                        ':filterstudentfull',
                        false
                    ) . ')';
                $like = '%' . $DB->sql_like_escape($filterstudent) . '%';
                $courseparams['filterstudentfirst'] = $like;
                $courseparams['filterstudentlast'] = $like;
                $courseparams['filterstudentfull'] = $like;
            }
        }

        $attempts = $DB->get_records_sql(
            "SELECT qa.id AS attemptid, qa.quiz AS quizid, qa.userid, qa.attempt, qa.uniqueid,
                    qa.timefinish, q.name AS quizname, q.course AS courseid,
                    c.fullname AS coursename, u.firstname, u.lastname,
                    cm.id AS cmid, cm.section, qa.sumgrades AS attemptsumgrades,
                    qg.grade AS overallgrade, am.cmid AS metacmid, am.courseid AS metacourseid,
                    am.instanceid AS metainstanceid, am.module AS metamodule,
                    am.unitid AS metaunitid, am.assessmenttype
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course} c ON c.id = q.course
               JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = :quizmodule
               JOIN {user} u ON u.id = qa.userid
          LEFT JOIN {quiz_grades} qg ON qg.quiz = q.id AND qg.userid = qa.userid
          LEFT JOIN {local_btiportal_assessment_meta} am ON am.cmid = cm.id
              WHERE qa.state = :finished
                AND qa.preview = 0
                AND cm.deletioninprogress = 0
                AND q.course {$coursesql}
                {$filtersql}
           ORDER BY qa.timefinish DESC, qa.id DESC",
            ['quizmodule' => $quizmoduleid, 'finished' => quiz_attempt::FINISHED] + $courseparams
        );

        $authorized = [];
        $candidatecount = count($attempts);
        $invalidassessmentcount = 0;
        $unauthorizedcount = 0;
        $capabilitydeniedcount = 0;
        foreach ($attempts as $attempt) {
            $assessment = self::assessment_details($attempt);
            if (!$assessment) {
                $invalidassessmentcount++;
                continue;
            }
            if (!self::user_can_grade($userid, $assessment, (int)$attempt->courseid)) {
                $unauthorizedcount++;
                continue;
            }

            $context = \context_module::instance((int)$attempt->cmid);
            if (!has_capability('mod/quiz:grade', $context, $userid)) {
                $capabilitydeniedcount++;
                continue;
            }
            $attempt->assessmenttype = $assessment['assessmenttype'];
            $attempt->resolvedunitid = $assessment['unitid'];
            $authorized[] = $attempt;
        }

        $stepsbyusage = self::latest_steps_by_usage(array_map(
            static fn(object $attempt): int => (int)$attempt->uniqueid,
            $authorized
        ));

        $queue = [];
        foreach ($authorized as $attempt) {
            $counts = self::summarize_steps($stepsbyusage[(int)$attempt->uniqueid] ?? []);
            $gradingstatus = self::grading_status($counts);
            if (!self::status_matches($filterstatus, $gradingstatus, $counts)) {
                continue;
            }

            $queue[] = [
                'attemptid' => (int)$attempt->attemptid,
                'quizid' => (int)$attempt->quizid,
                'cmid' => (int)$attempt->cmid,
                'userid' => (int)$attempt->userid,
                'student' => fullname($attempt),
                'courseid' => (int)$attempt->courseid,
                'course' => (string)$attempt->coursename,
                'assessment' => (string)$attempt->quizname,
                'assessmenttype' => (string)$attempt->assessmenttype,
                'unitid' => (int)$attempt->resolvedunitid,
                'attemptnumber' => (int)$attempt->attempt,
                'submitted' => (int)$attempt->timefinish,
                'attemptsumgrades' => $attempt->attemptsumgrades === null
                    ? -1.0
                    : (float)$attempt->attemptsumgrades,
                'overallgrade' => $attempt->overallgrade === null
                    ? -1.0
                    : (float)$attempt->overallgrade,
                'questioncount' => array_sum($counts),
                'pendingcount' => $counts['needsgrading'],
                'autogradedcount' => $counts['autograded'],
                'manuallygradedcount' => $counts['manuallygraded'],
                'inprogresscount' => $counts['inprogress'],
                'gradingstatus' => $gradingstatus,
            ];
        }

        $queuetotal = count($queue);
        $fullygradedcount = count(array_filter(
            $queue,
            static fn(array $row): bool => $row['gradingstatus'] === 'graded'
        ));
        if ($perpage > 0) {
            $queue = array_slice($queue, $page * $perpage, $perpage);
        }
        foreach ($queue as &$queuerow) {
            $queuerow['queuetotal'] = $queuetotal;
            $queuerow['fullygradedcount'] = $fullygradedcount;
        }
        unset($queuerow);

        debugging(
            'local_btiportal quiz grading queue stages: database candidates=' . $candidatecount
                . ', invalid assessment=' . $invalidassessmentcount
                . ', unauthorized=' . $unauthorizedcount
                . ', capability denied=' . $capabilitydeniedcount
                . ', returned=' . count($queue),
            DEBUG_DEVELOPER
        );

        return $queue;
    }

    /**
     * Load a submitted attempt and its responses after checking the BTI assignment and Moodle capability.
     *
     * @return array<string,mixed>
     */
    public static function get_attempt_for_grading(
        int $attemptid,
        int $courseid,
        int $quizid,
        int $userid
    ): array {
        global $DB;

        [$attempt, $attemptobj, $assessment, $context] = self::load_authorized_attempt(
            $attemptid,
            $courseid,
            $quizid,
            $userid
        );
        if ($attempt->state !== quiz_attempt::FINISHED || !empty($attempt->preview)) {
            throw new \invalid_parameter_exception('Only finished quiz attempts can be reviewed.');
        }

        $usage = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);
        $questions = [];
        foreach ($usage->get_slots() as $slot) {
            $questionattempt = $usage->get_question_attempt($slot);
            $question = $usage->get_question($slot, false);
            [$comment, $commentformat] = $questionattempt->get_manual_comment();
            $state = $questionattempt->get_state()->get_summary_state();
            $questions[] = [
                'slot' => (int)$slot,
                'questionid' => (int)$questionattempt->get_question_id(),
                'type' => (string)$attemptobj->get_question_type_name($slot),
                'name' => format_string((string)$question->name, true, ['context' => $context]),
                'questiontext' => format_text(
                    (string)$question->questiontext,
                    (int)$question->questiontextformat,
                    ['context' => $context]
                ),
                'response' => self::question_response($questionattempt, $question),
                'maxmark' => (float)$questionattempt->get_max_mark(),
                'currentmark' => $questionattempt->get_mark() === null
                    ? -1.0
                    : (float)$questionattempt->get_mark(),
                'gradingstate' => $state,
                'eligibleformanualgrading' => in_array($state, ['needsgrading', 'manuallygraded'], true),
                'feedback' => self::plain_feedback($comment, $commentformat),
                'commentformat' => (int)($commentformat ?? FORMAT_PLAIN),
                'version' => (int)$questionattempt->get_last_step()->get_id(),
                'files' => self::response_files($questionattempt, $context),
            ];
        }

        $counts = self::summarize_usage($usage);
        return [
            'attemptid' => (int)$attempt->id,
            'attemptnumber' => (int)$attempt->attempt,
            'quizid' => (int)$attempt->quiz,
            'cmid' => (int)$attemptobj->get_cmid(),
            'userid' => (int)$attempt->userid,
            'student' => fullname($DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST)),
            'courseid' => (int)$courseid,
            'course' => (string)$attemptobj->get_course()->fullname,
            'assessment' => format_string((string)$attemptobj->get_quiz_name(), true, ['context' => $context]),
            'assessmenttype' => $assessment['assessmenttype'],
            'unitid' => $assessment['unitid'],
            'submitted' => (int)$attempt->timefinish,
            'attemptsumgrades' => $attempt->sumgrades === null ? -1.0 : (float)$attempt->sumgrades,
            'overallgrade' => self::get_overall_grade((int)$attempt->quiz, (int)$attempt->userid),
            'questioncount' => array_sum($counts),
            'pendingcount' => $counts['needsgrading'],
            'autogradedcount' => $counts['autograded'],
            'manuallygradedcount' => $counts['manuallygraded'],
            'inprogresscount' => $counts['inprogress'],
            'gradingstatus' => self::grading_status($counts),
            'queuetotal' => 1,
            'fullygradedcount' => self::grading_status($counts) === 'graded' ? 1 : 0,
            'studentcanseemark' => self::student_can_see_marks($attemptobj, $attempt, $context),
            'questions' => $questions,
        ];
    }

    /**
     * Grade one question using Moodle's question engine and quiz grade calculator.
     *
     * @return array<string,mixed>
     */
    public static function save_manual_grade(
        int $attemptid,
        int $courseid,
        int $quizid,
        int $slot,
        float $mark,
        string $feedback,
        int $userid,
        int $expectedversion = 0,
        int $commentformat = FORMAT_PLAIN
    ): array {
        global $DB;

        [$attempt, $attemptobj, , $context] = self::load_authorized_attempt(
            $attemptid,
            $courseid,
            $quizid,
            $userid
        );
        if ($attempt->state !== quiz_attempt::FINISHED || !empty($attempt->preview)) {
            throw new \invalid_parameter_exception('Only finished quiz attempts can be graded.');
        }

        $usage = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);
        if (!in_array($slot, $usage->get_slots(), true)) {
            throw new \invalid_parameter_exception('The question slot does not belong to this attempt.');
        }

        $questionattempt = $usage->get_question_attempt($slot);
        $state = $questionattempt->get_state()->get_summary_state();
        if (!in_array($state, ['needsgrading', 'manuallygraded'], true)) {
            throw new \invalid_parameter_exception('This question is not eligible for manual grading.');
        }
        if (!is_finite($mark)) {
            throw new \invalid_parameter_exception('The mark must be a finite number.');
        }
        $maxmark = (float)$questionattempt->get_max_mark();
        if ($mark < 0 || $mark > $maxmark) {
            throw new \invalid_parameter_exception('The mark must be between 0 and the question maximum.');
        }

        $validationerror = $questionattempt->validate_manual_mark((string)$mark);
        if ($validationerror !== '') {
            throw new \invalid_parameter_exception('Invalid mark: ' . $validationerror);
        }

        $currentversion = (int)$questionattempt->get_last_step()->get_id();
        if ($expectedversion > 0 && $expectedversion !== $currentversion) {
            throw new \moodle_exception('gradingconflict', 'local_btiportal');
        }

        self::assert_quiz_grade_writable($courseid, $quizid, (int)$attempt->userid);

        [$currentcomment, $currentcommentformat] = $questionattempt->get_manual_comment();
        $currentmark = $questionattempt->get_mark();
        $unchanged = $currentmark !== null
            && abs((float)$currentmark - $mark) < 0.00001
            && trim((string)$currentcomment) === trim($feedback)
            && (int)($currentcommentformat ?? FORMAT_PLAIN) === $commentformat;
        if ($unchanged) {
            $quizgrade = $DB->get_field('quiz_grades', 'grade', [
                'quiz' => $quizid,
                'userid' => (int)$attempt->userid,
            ]);
            return [
                'attemptid' => $attemptid,
                'quizid' => $quizid,
                'slot' => $slot,
                'mark' => (float)$currentmark,
                'feedback' => self::plain_feedback($currentcomment, $currentcommentformat),
                'attemptsumgrades' => $attempt->sumgrades === null ? -1.0 : (float)$attempt->sumgrades,
                'quizgrade' => $quizgrade === false ? -1.0 : (float)$quizgrade,
                'unchanged' => true,
                'notified' => false,
                'version' => $currentversion,
            ];
        }

        $transaction = $DB->start_delegated_transaction();
        $usage->manual_grade($slot, $feedback, $mark, $commentformat);
        \question_engine::save_questions_usage_by_activity($usage);

        $quizsettings = quiz_settings::create($quizid);
        $gradecalculator = $quizsettings->get_grade_calculator();
        $gradecalculator->recompute_all_attempt_sumgrades();
        $gradecalculator->recompute_final_grade((int)$attempt->userid);

        $updatedattempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $updatedquestion = $usage->get_question_attempt($slot);
        [$updatedfeedback] = $updatedquestion->get_manual_comment();

        $event = \mod_quiz\event\question_manually_graded::create([
            'objectid' => (int)$updatedquestion->get_question_id(),
            'courseid' => $courseid,
            'context' => $context,
            'other' => [
                'quizid' => $quizid,
                'attemptid' => $attemptid,
                'slot' => $slot,
            ],
        ]);
        audit_helper::log([
            'action' => 'quiz_manual_grade',
            'entitytype' => 'quiz_attempt',
            'entityid' => $attemptid,
            'before' => [
                'slot' => $slot,
                'mark' => $currentmark === null ? null : (float)$currentmark,
                'comment' => (string)$currentcomment,
                'questionid' => (int)$questionattempt->get_question_id(),
            ],
            'after' => [
                'slot' => $slot,
                'mark' => $mark,
                'comment' => $feedback,
                'quizid' => $quizid,
                'userid' => (int)$attempt->userid,
                'questionid' => (int)$updatedquestion->get_question_id(),
            ],
            'sourcemodule' => 'quiz_grading',
        ], $userid);
        $transaction->allow_commit();
        $event->trigger();

        $quizgrade = $DB->get_field('quiz_grades', 'grade', [
            'quiz' => $quizid,
            'userid' => (int)$attempt->userid,
        ]);

        $updatedusage = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);
        $updatedcounts = self::summarize_usage($updatedusage);
        $notified = false;
        if ($updatedcounts['needsgrading'] === 0 && self::student_can_see_marks($attemptobj, $updatedattempt, $context)) {
            $notified = self::notify_grade_released(
                (int)$attempt->userid,
                $userid,
                $courseid,
                'Quiz marked',
                'Your ' . format_string($attemptobj->get_quiz_name()) . ' attempt has been marked.'
            );
        }

        return [
            'attemptid' => $attemptid,
            'quizid' => $quizid,
            'slot' => $slot,
            'mark' => (float)$updatedquestion->get_mark(),
            'feedback' => self::plain_feedback($updatedfeedback, FORMAT_PLAIN),
            'attemptsumgrades' => (float)$updatedattempt->sumgrades,
            'quizgrade' => $quizgrade === false ? -1.0 : (float)$quizgrade,
            'unchanged' => false,
            'notified' => $notified,
            'version' => (int)$updatedquestion->get_last_step()->get_id(),
        ];
    }

    /**
     * Resolve the assessment's authorized unit; final exams explicitly use unitid zero.
     *
     * @return array{assessmenttype:string,unitid:int}|null
     */
    private static function assessment_details(object $record): ?array {
        $hasmetadata = (int)($record->metacmid ?? 0) > 0;
        if ($hasmetadata
                && ((int)$record->metacourseid !== (int)$record->courseid
                    || (int)$record->metainstanceid !== (int)$record->quizid
                    || (string)$record->metamodule !== 'quiz')) {
            return null;
        }

        $assessmenttype = strtolower(trim((string)($record->assessmenttype ?? 'quiz')));
        if ($assessmenttype === 'final') {
            if (!$hasmetadata) {
                return null;
            }
            if ((int)$record->metaunitid !== 0) {
                return null;
            }
            return ['assessmenttype' => $assessmenttype, 'unitid' => 0];
        }

        $unitid = $hasmetadata
            ? (int)$record->metaunitid
            : (int)$record->section;
        if ($unitid <= 0) {
            return null;
        }
        return [
            'assessmenttype' => $assessmenttype !== '' ? $assessmenttype : 'quiz',
            'unitid' => $unitid,
        ];
    }

    /**
     * @param array{assessmenttype:string,unitid:int} $assessment
     */
    private static function user_can_grade(int $userid, array $assessment, int $courseid): bool {
        if (is_siteadmin($userid)) {
            return true;
        }
        if ($assessment['unitid'] === 0) {
            return $assessment['assessmenttype'] === 'final'
                && lecturer_teaching_helper::user_teaches_moodle_course($userid, $courseid);
        }
        return gradebook_helper::user_is_unit_teacher($userid, $courseid, $assessment['unitid']);
    }

    /**
     * Check the attempt identifiers, BTI teacher assignment and quiz capability.
     *
     * @return array{0:object,1:quiz_attempt,2:array{assessmenttype:string,unitid:int},3:\context_module}
     */
    private static function load_authorized_attempt(
        int $attemptid,
        int $courseid,
        int $quizid,
        int $userid
    ): array {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        if ((int)$attempt->quiz !== $quizid) {
            throw new \invalid_parameter_exception('The attempt does not belong to the expected quiz.');
        }
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        if ((int)$quiz->course !== $courseid) {
            throw new \invalid_parameter_exception('The quiz does not belong to the expected course.');
        }

        $attemptobj = quiz_attempt::create($attemptid);
        $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        $metadata = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cm->id]);
        $record = (object)[
            'courseid' => $courseid,
            'quizid' => $quizid,
            'cmid' => (int)$cm->id,
            'section' => (int)$cm->section,
            'metacmid' => $metadata ? (int)$metadata->cmid : 0,
            'metacourseid' => $metadata ? (int)$metadata->courseid : 0,
            'metainstanceid' => $metadata ? (int)$metadata->instanceid : 0,
            'metamodule' => $metadata ? (string)$metadata->module : '',
            'metaunitid' => $metadata ? (int)$metadata->unitid : 0,
            'assessmenttype' => $metadata ? (string)$metadata->assessmenttype : 'quiz',
        ];
        $assessment = self::assessment_details($record);
        if (!$assessment || !self::user_can_grade($userid, $assessment, $courseid)) {
            throw new \moodle_exception(
                $assessment && $assessment['unitid'] === 0 ? 'notcourseteacher' : 'notunitteacher',
                'local_btiportal',
                '',
                (object)['courseid' => $courseid, 'unitid' => $assessment['unitid'] ?? 0]
            );
        }
        if (!has_capability('mod/quiz:grade', $context, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'grade quiz');
        }

        return [$attempt, $attemptobj, $assessment, $context];
    }

    /**
     * @return array{needsgrading:int,autograded:int,manuallygraded:int,inprogress:int}
     */
    private static function summarize_usage(\question_usage_by_activity $usage): array {
        $counts = [
            'needsgrading' => 0,
            'autograded' => 0,
            'manuallygraded' => 0,
            'inprogress' => 0,
        ];
        foreach ($usage->get_slots() as $slot) {
            $summary = $usage->get_question_state($slot)->get_summary_state();
            if (array_key_exists($summary, $counts)) {
                $counts[$summary]++;
            }
        }
        return $counts;
    }

    /**
     * @param list<int> $usageids
     * @return array<int, list<object>>
     */
    private static function latest_steps_by_usage(array $usageids): array {
        $usageids = array_values(array_unique(array_filter(array_map('intval', $usageids))));
        if (!$usageids) {
            return [];
        }
        $mapper = new \question_engine_data_mapper();
        $grouped = [];
        foreach ($mapper->load_questions_usages_latest_steps(new \qubaid_list($usageids)) as $step) {
            $grouped[(int)$step->questionusageid][] = $step;
        }
        return $grouped;
    }

    /**
     * @param list<object> $steps
     * @return array{needsgrading:int,autograded:int,manuallygraded:int,inprogress:int}
     */
    private static function summarize_steps(array $steps): array {
        $counts = [
            'needsgrading' => 0,
            'autograded' => 0,
            'manuallygraded' => 0,
            'inprogress' => 0,
        ];
        foreach ($steps as $step) {
            $state = \question_state::get((string)$step->state);
            if (!$state) {
                continue;
            }
            $summary = $state->get_summary_state();
            if (array_key_exists($summary, $counts)) {
                $counts[$summary]++;
            }
        }
        return $counts;
    }

    /**
     * @param array{needsgrading:int,autograded:int,manuallygraded:int,inprogress:int} $counts
     */
    private static function grading_status(array $counts): string {
        if ($counts['needsgrading'] > 0 && $counts['manuallygraded'] > 0) {
            return 'partial';
        }
        if ($counts['needsgrading'] > 0 || $counts['inprogress'] > 0) {
            return 'untouched';
        }
        return 'graded';
    }

    /**
     * @param array{needsgrading:int,autograded:int,manuallygraded:int,inprogress:int} $counts
     */
    private static function status_matches(string $filter, string $status, array $counts): bool {
        if ($filter === '' || $filter === 'all') {
            return true;
        }
        if ($filter === 'needsgrading' || $filter === 'pending') {
            return $counts['needsgrading'] > 0;
        }
        if ($filter === 'partial' || $filter === 'partlygraded') {
            return $status === 'partial';
        }
        return $status === $filter;
    }

    /**
     * @param list<int> $quizids
     * @return array<int, int> quiz id => finished attempts with a question that needs grading
     */
    public static function pending_attempt_counts(array $quizids): array {
        global $DB;

        $quizids = array_values(array_unique(array_filter(array_map('intval', $quizids))));
        $counts = array_fill_keys($quizids, 0);
        if (!$quizids) {
            return $counts;
        }
        [$insql, $params] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'pendingquiz');
        $params['finished'] = quiz_attempt::FINISHED;
        $attempts = $DB->get_records_sql(
            "SELECT id, uniqueid, quiz
               FROM {quiz_attempts}
              WHERE quiz {$insql}
                AND state = :finished
                AND preview = 0",
            $params
        );
        $quizbyusage = [];
        foreach ($attempts as $attempt) {
            $quizbyusage[(int)$attempt->uniqueid] = (int)$attempt->quiz;
        }
        $pendingusages = [];
        foreach (self::latest_steps_by_usage(array_keys($quizbyusage)) as $usageid => $steps) {
            foreach ($steps as $step) {
                $state = \question_state::get((string)$step->state);
                if ($state && $state->get_summary_state() === 'needsgrading') {
                    $pendingusages[$usageid] = true;
                    break;
                }
            }
        }
        foreach (array_keys($pendingusages) as $usageid) {
            $quizid = $quizbyusage[$usageid] ?? 0;
            if (array_key_exists($quizid, $counts)) {
                $counts[$quizid]++;
            }
        }
        return $counts;
    }

    /**
     * @return array<int, true> quiz ids whose finished attempts still need manual grading
     */
    public static function pending_quiz_ids_for_user(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }
        $attempts = $DB->get_records_sql(
            "SELECT id, uniqueid, quiz
               FROM {quiz_attempts}
              WHERE userid = :userid
                AND state = :finished
                AND preview = 0",
            ['userid' => $userid, 'finished' => quiz_attempt::FINISHED]
        );
        $quizbyusage = [];
        foreach ($attempts as $attempt) {
            $quizbyusage[(int)$attempt->uniqueid] = (int)$attempt->quiz;
        }
        $pending = [];
        foreach (self::latest_steps_by_usage(array_keys($quizbyusage)) as $usageid => $steps) {
            foreach ($steps as $step) {
                $state = \question_state::get((string)$step->state);
                if ($state && $state->get_summary_state() === 'needsgrading') {
                    $pending[$quizbyusage[$usageid]] = true;
                    break;
                }
            }
        }
        return $pending;
    }

    private static function plain_feedback(?string $feedback, ?int $format): string {
        if ($feedback === null || $feedback === '') {
            return '';
        }

        return $format === FORMAT_HTML ? html_to_text($feedback) : $feedback;
    }

    private static function get_overall_grade(int $quizid, int $userid): float {
        global $DB;

        $grade = $DB->get_field('quiz_grades', 'grade', ['quiz' => $quizid, 'userid' => $userid]);
        return $grade === false || $grade === null ? -1.0 : (float)$grade;
    }

    private static function question_response(
        \question_attempt $questionattempt,
        \question_definition $question
    ): string {
        if ($question->get_type_name() === 'essay') {
            $response = $questionattempt->get_last_qt_data();
            $answer = $response['answer'] ?? '';
            if (is_string($answer) && $answer !== '') {
                $format = (int)($response['answerformat'] ?? FORMAT_PLAIN);
                return $format === FORMAT_HTML ? html_to_text($answer) : $answer;
            }
        }
        return (string)($questionattempt->get_response_summary() ?? '');
    }

    /**
     * @return list<array{filename:string,filesize:int,mimetype:string,fileurl:string}>
     */
    private static function response_files(
        \question_attempt $questionattempt,
        \context $context
    ): array {
        $files = [];
        foreach (['attachments', 'answer'] as $name) {
            foreach ($questionattempt->get_last_qt_files($name, $context->id) as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                $files[] = [
                    'filename' => $file->get_filename(),
                    'filesize' => (int)$file->get_filesize(),
                    'mimetype' => (string)$file->get_mimetype(),
                    'fileurl' => $questionattempt->get_response_file_url($file),
                ];
            }
        }
        return $files;
    }

    private static function student_can_see_marks(
        quiz_attempt $attemptobj,
        object $attempt,
        \context $context
    ): bool {
        $options = quiz_get_review_options($attemptobj->get_quiz(), $attempt, $context);
        return !empty($options->marks);
    }

    private static function assert_quiz_grade_writable(int $courseid, int $quizid, int $studentid): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');

        $gradeitem = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
        ]);
        if (!$gradeitem) {
            return;
        }
        if (!empty($gradeitem->locked)) {
            throw new \moodle_exception('gradelocked', 'local_btiportal');
        }
        $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $studentid]);
        if (!$gradegrade) {
            return;
        }
        if (!empty($gradegrade->overridden)) {
            throw new \moodle_exception('gradeoverridden', 'local_btiportal');
        }
        if (!empty($gradegrade->locked)) {
            throw new \moodle_exception('gradelocked', 'local_btiportal');
        }
    }

    public static function notify_grade_released(
        int $studentid,
        int $actorid,
        int $courseid,
        string $title,
        string $body
    ): bool {
        global $DB;

        if ($studentid <= 0 || !$DB->get_manager()->table_exists('local_btiportal_notification')) {
            return false;
        }
        $now = time();
        $DB->insert_record('local_btiportal_notification', (object)[
            'authorid' => $actorid,
            'title' => substr($title, 0, 255),
            'body' => $body,
            'scope' => 'selected_users',
            'departmentid' => 0,
            'schoolid' => 0,
            'programmeid' => 0,
            'courseunitid' => 0,
            'moodlecourseid' => $courseid,
            'targetjson' => json_encode(['userids' => [$studentid]]),
            'status' => 'sent',
            'timescheduled' => 0,
            'timesent' => $now,
            'deliverystatus' => 'portal_record',
            'deliverydetail' => 'Recorded after the grade was released to the student.',
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return true;
    }
}
