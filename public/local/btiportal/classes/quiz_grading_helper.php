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
    public static function get_queue(int $userid): array {
        global $DB;

        $quizmoduleid = (int)$DB->get_field('modules', 'id', ['name' => 'quiz'], IGNORE_MISSING);
        if ($quizmoduleid <= 0) {
            return [];
        }

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
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        if (!$courseids) {
            return [];
        }
        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'teacher_course');

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
           ORDER BY qa.timefinish DESC, qa.id DESC",
            ['quizmodule' => $quizmoduleid, 'finished' => quiz_attempt::FINISHED] + $courseparams
        );

        $queue = [];
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

            $usage = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);
            $counts = self::summarize_usage($usage);

            $queue[] = [
                'attemptid' => (int)$attempt->attemptid,
                'quizid' => (int)$attempt->quizid,
                'cmid' => (int)$attempt->cmid,
                'userid' => (int)$attempt->userid,
                'student' => fullname($attempt),
                'courseid' => (int)$attempt->courseid,
                'course' => (string)$attempt->coursename,
                'assessment' => (string)$attempt->quizname,
                'assessmenttype' => $assessment['assessmenttype'],
                'unitid' => $assessment['unitid'],
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
            ];
        }

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
        int $userid
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

        $validationerror = $questionattempt->validate_manual_mark((string)$mark);
        if ($validationerror !== '') {
            throw new \invalid_parameter_exception('Invalid mark: ' . $validationerror);
        }

        $transaction = $DB->start_delegated_transaction();
        $usage->manual_grade($slot, $feedback, $mark, FORMAT_PLAIN);
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
        $transaction->allow_commit();
        $event->trigger();

        $quizgrade = $DB->get_field('quiz_grades', 'grade', [
            'quiz' => $quizid,
            'userid' => (int)$attempt->userid,
        ]);

        return [
            'attemptid' => $attemptid,
            'quizid' => $quizid,
            'slot' => $slot,
            'mark' => (float)$updatedquestion->get_mark(),
            'feedback' => self::plain_feedback($updatedfeedback, FORMAT_PLAIN),
            'attemptsumgrades' => (float)$updatedattempt->sumgrades,
            'quizgrade' => $quizgrade === false ? -1.0 : (float)$quizgrade,
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
}
