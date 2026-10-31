<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/gradelib.php');

use mod_quiz\question\display_options;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Create and manage portal assessments as native Moodle quiz/assign activities.
 */
class assessment_helper {
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_QUIZ = 'quiz';
    public const TYPE_CAT = 'cat';
    public const TYPE_MIDTERM = 'midterm';
    public const TYPE_FINAL = 'final';
    public const TYPE_TEST = 'test';
    public const TYPE_PRACTICAL = 'practical';

    public const ACTION_FLAG = 'flag';
    public const ACTION_SUBMIT = 'submit';
    public const ACTION_SUBMIT_FLAG = 'submit_flag';

    public const MODULE_QUIZ = 'quiz';
    public const MODULE_ASSIGN = 'assign';

    /** @return list<string> */
    public static function allowed_types(): array {
        return [
            self::TYPE_ASSIGNMENT,
            self::TYPE_QUIZ,
            self::TYPE_CAT,
            self::TYPE_MIDTERM,
            self::TYPE_FINAL,
            self::TYPE_TEST,
            self::TYPE_PRACTICAL,
        ];
    }

    /** @return list<string> */
    public static function allowed_actions(): array {
        return [self::ACTION_FLAG, self::ACTION_SUBMIT, self::ACTION_SUBMIT_FLAG];
    }

    public static function normalize_type(string $type): string {
        $t = strtolower(trim($type));
        if ($t === 'exam') {
            $t = self::TYPE_FINAL;
        }
        if (!in_array($t, self::allowed_types(), true)) {
            throw new \moodle_exception('invalidassessmenttype', 'local_btiportal', '', $type);
        }
        return $t;
    }

    public static function normalize_action(string $action): string {
        $a = strtolower(trim($action));
        if ($a === 'submit+flag' || $a === 'submitandflag') {
            $a = self::ACTION_SUBMIT_FLAG;
        }
        if (!in_array($a, self::allowed_actions(), true)) {
            throw new \moodle_exception('invalidwarningaction', 'local_btiportal', '', $action);
        }
        return $a;
    }

    /** Map portal type onto existing CAT / EXAM / ASSIGNMENT gradebook buckets. */
    public static function gradebook_category(string $type): string {
        $type = self::normalize_type($type);
        return match ($type) {
            self::TYPE_ASSIGNMENT, self::TYPE_PRACTICAL => assignment_meta_helper::CATEGORY_ASSIGNMENT,
            self::TYPE_MIDTERM, self::TYPE_FINAL => assignment_meta_helper::CATEGORY_EXAM,
            default => assignment_meta_helper::CATEGORY_CAT,
        };
    }

    public static function uses_quiz(string $type): bool {
        return self::normalize_type($type) !== self::TYPE_ASSIGNMENT;
    }

    /**
     * Require the Moodle capabilities used to add a quiz and its questions.
     */
    public static function require_quiz_creation_capabilities(\context_course $context): void {
        if (!has_capability('moodle/course:manageactivities', $context)
            || !has_capability('moodle/question:add', $context)) {
            throw new \moodle_exception('missingquizcapability', 'local_btiportal');
        }
    }

    /**
     * Create a Moodle quiz, serializing Final Exam requests to make retries safe.
     *
     * @param array $questions Decoded question objects from the portal editor
     * @return array<string,mixed>
     */
    public static function create_quiz_assessment(
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
        array $questions
    ): array {
        $assessmenttype = self::normalize_type($assessmenttype);
        if ($assessmenttype !== self::TYPE_FINAL) {
            return self::create_quiz_assessment_unlocked(
                $courseid,
                $unitid,
                $name,
                $intro,
                $assessmenttype,
                $grademax,
                $passmark,
                $timeopen,
                $timeclose,
                $timelimit,
                $maxattempts,
                $shufflequestions,
                $shuffleanswers,
                $requirefullscreen,
                $maxwarnings,
                $warningaction,
                $questions
            );
        }

        global $USER;
        $lockkey = 'final-' . hash('sha256', serialize([
            (int)$USER->id,
            $courseid,
            $unitid,
            trim($name),
        ]));
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_btiportal');
        $lock = $lockfactory->get_lock($lockkey, 10);
        if (!$lock) {
            throw new \moodle_exception('quizcreationbusy', 'local_btiportal');
        }

        try {
            return self::create_quiz_assessment_unlocked(
                $courseid,
                $unitid,
                $name,
                $intro,
                $assessmenttype,
                $grademax,
                $passmark,
                $timeopen,
                $timeclose,
                $timelimit,
                $maxattempts,
                $shufflequestions,
                $shuffleanswers,
                $requirefullscreen,
                $maxwarnings,
                $warningaction,
                $questions
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Create a quiz and its question/grade metadata after authorization.
     *
     * @param array $questions Decoded question objects from the portal editor
     * @return array<string,mixed>
     */
    private static function create_quiz_assessment_unlocked(
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
        array $questions
    ): array {
        global $DB, $USER;

        $assessmenttype = self::normalize_type($assessmenttype);
        $warningaction = self::normalize_action($warningaction);
        if (!self::uses_quiz($assessmenttype)) {
            throw new \invalid_parameter_exception('Use the assignment endpoint for assignment type');
        }
        $isfinal = $assessmenttype === self::TYPE_FINAL;
        if ($isfinal && $unitid !== 0) {
            throw new \invalid_parameter_exception('Final exams must not be associated with a course unit');
        }
        if (!$isfinal && $unitid <= 0) {
            throw new \invalid_parameter_exception('A course unit is required for this assessment type');
        }
        if ($maxwarnings < 0) {
            $maxwarnings = 0;
        }
        if ($maxwarnings === 0) {
            $maxwarnings = 3;
        }

        $course = get_course($courseid);
        $section = $isfinal
            ? $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => 0,
            ], '*', MUST_EXIST)
            : $DB->get_record('course_sections', [
                'id' => $unitid,
                'course' => $courseid,
            ], '*', MUST_EXIST);

        $name = trim($name);
        if ($name === '') {
            throw new \moodle_exception('invalidname', 'error');
        }
        if (empty($questions)) {
            throw new \invalid_parameter_exception('At least one question is required');
        }

        $introhtml = self::to_html($intro);
        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        $sectionnum = (int)$section->section;
        if ($grademax <= 0) {
            $grademax = 100.0;
        }
        if ($isfinal && ($passmark < 0 || $passmark > $grademax)) {
            throw new \invalid_parameter_exception('Final exam pass mark must be within the grade range');
        }
        $passmark = max(0.0, $passmark);
        $timeopen = max(0, $timeopen);
        $timeclose = max(0, $timeclose);
        if ($isfinal && ($timeopen === 0 || $timeclose === 0)) {
            throw new \invalid_parameter_exception('Final exams require start and end times');
        }
        $timelimit = max(0, $timelimit);
        if ($timeclose > 0 && $timeopen > 0 && $timeclose < $timeopen) {
            throw new \invalid_parameter_exception('End time must be after the start time');
        }

        $requestid = '';
        if ($isfinal) {
            $requestid = 'bti-final-' . hash('sha256', serialize([
                (int)$USER->id,
                $courseid,
                $unitid,
                $name,
                $intro,
                $assessmenttype,
                $grademax,
                $passmark,
                $timeopen,
                $timeclose,
                $timelimit,
                $maxattempts,
                $shufflequestions,
                $shuffleanswers,
                $requirefullscreen,
                $maxwarnings,
                $warningaction,
                $questions,
            ]));
            $existing = $DB->get_record_sql(
                "SELECT cm.id AS cmid, cm.instance AS quizid, q.name, q.intro,
                        q.timeopen, q.timeclose, q.timelimit, q.attempts, q.grade,
                        gi.id AS gradeitemid, gi.grademax
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                   JOIN {quiz} q ON q.id = cm.instance AND q.course = cm.course
              LEFT JOIN {grade_items} gi ON gi.courseid = cm.course
                    AND gi.itemtype = :itemtype AND gi.itemmodule = :itemmodule
                    AND gi.iteminstance = q.id AND gi.itemnumber = 0
                  WHERE cm.course = :courseid
                    AND cm.idnumber = :requestid",
                [
                    'modname' => self::MODULE_QUIZ,
                    'itemtype' => 'mod',
                    'itemmodule' => self::MODULE_QUIZ,
                    'courseid' => $courseid,
                    'requestid' => $requestid,
                ],
                IGNORE_MULTIPLE
            );
            if ($existing) {
                $meta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $existing->cmid]);
                if (!$meta || $meta->assessmenttype !== self::TYPE_FINAL || (int)$meta->unitid !== 0) {
                    throw new \moodle_exception('error', 'error', '', null, 'Assessment retry key conflicts with existing course activity');
                }
                return [
                    'courseid' => $courseid,
                    'unitid' => 0,
                    'quizid' => (int)$existing->quizid,
                    'cmid' => (int)$existing->cmid,
                    'name' => (string)$existing->name,
                    'intro' => (string)$existing->intro,
                    'gradeitemid' => (int)($existing->gradeitemid ?? 0),
                    'grademax' => (float)$existing->grademax,
                    'passmark' => (float)$meta->passmark,
                    'mapped' => false,
                    'assessmenttype' => self::TYPE_FINAL,
                    'questioncount' => (int)$DB->count_records('quiz_slots', ['quizid' => $existing->quizid]),
                    'timeopen' => (int)$existing->timeopen,
                    'timeclose' => (int)$existing->timeclose,
                    'timelimit' => (int)$existing->timelimit,
                    'maxattempts' => (int)$existing->attempts,
                    'requirefullscreen' => (int)$meta->requirefullscreen,
                    'maxwarnings' => (int)$meta->maxwarnings,
                    'warningaction' => (string)$meta->warningaction,
                ];
            }
        }

        // Moodle quiz: 0 attempts = unlimited.
        $attempts = max(0, $maxattempts);
        $reviewafter = display_options::AFTER_CLOSE | display_options::LATER_WHILE_OPEN;
        $transaction = $DB->start_delegated_transaction();

        $fromform = (object)[
            'name' => $name,
            'course' => $courseid,
            'section' => $sectionnum,
            'module' => (int)$module->id,
            'modulename' => 'quiz',
            'instance' => 0,
            'intro' => $introhtml,
            'introformat' => FORMAT_HTML,
            'timeopen' => $timeopen,
            'timeclose' => $timeclose,
            'timelimit' => $timelimit,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 0,
            'preferredbehaviour' => 'deferredfeedback',
            'canredoquestions' => 0,
            'attempts' => $attempts,
            'attemptonlast' => 0,
            'grademethod' => 1,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'reviewattempt' => $reviewafter,
            'reviewcorrectness' => 0,
            'reviewmaxmarks' => $reviewafter,
            'reviewmarks' => $reviewafter,
            'reviewspecificfeedback' => 0,
            'reviewgeneralfeedback' => 0,
            'reviewrightanswer' => 0,
            'reviewoverallfeedback' => 0,
            'questionsperpage' => 1,
            'navmethod' => 'free',
            'shuffleanswers' => $shuffleanswers ? 1 : 0,
            'sumgrades' => 0,
            'grade' => $grademax,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => $requestid,
            'groupmode' => 0,
            'groupingid' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
            'completionexpected' => 0,
            'showdescription' => 1,
            'availabilityconditionsjson' => '',
            'quizpassword' => '',
            'subnet' => '',
            'delay1' => 0,
            'delay2' => 0,
            'browsersecurity' => '-',
            'boundary_repeats' => 0,
        ];

        try {
            $cm = add_moduleinfo($fromform, $course);
        } catch (\invalid_parameter_exception $exception) {
            throw $exception;
        } catch (\moodle_exception $exception) {
            throw new \moodle_exception('quizcreationfailed', 'local_btiportal', '', null, $exception->getMessage());
        }
        $cmid = (int)($cm->coursemodule ?? 0);
        $quizid = (int)($cm->instance ?? 0);
        if ($cmid <= 0 || $quizid <= 0) {
            throw new \moodle_exception('quizcreationfailed', 'local_btiportal');
        }

        $cmrec = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        if ($section && (int)$cmrec->section !== (int)$section->id) {
            $DB->set_field('course_modules', 'section', $section->id, ['id' => $cmid]);
            rebuild_course_cache($courseid, true);
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $quiz->cmid = $cmid;
        $quiz->course = $courseid;

        $modcontext = \context_module::instance($cmid);
        $category = question_get_default_category($modcontext->id, true);
        if (!$category) {
            throw new \moodle_exception('quizcreationfailed', 'local_btiportal');
        }

        $created = [];
        $page = 1;
        foreach ($questions as $index => $qdata) {
            if (!is_array($qdata)) {
                continue;
            }
            $questionid = self::create_question($category, $qdata, (bool)$shuffleanswers, $index + 1);
            $mark = self::question_mark($qdata);
            quiz_add_quiz_question($questionid, $quiz, $page, $mark);
            $created[] = $questionid;
            $page++;
        }
        if (empty($created)) {
            course_delete_module($cmid);
            throw new \invalid_parameter_exception('No valid questions could be created');
        }

        if ($shufflequestions) {
            $DB->set_field('quiz_sections', 'shufflequestions', 1, ['quizid' => $quizid]);
        }

        $quizobj = quiz_settings::create($quizid);
        $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();

        $gi = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
        ]);
        if (!$gi) {
            throw new \moodle_exception('quizcreationfailed', 'local_btiportal');
        }
        $gradeitemid = $gi ? (int)$gi->id : 0;
        $mapped = false;
        if ($gradeitemid > 0 && !$isfinal) {
            gradebook_helper::map_grade_item($courseid, $unitid, $gradeitemid);
            $mapped = true;
        }

        if ($passmark > 0 && $gi) {
            $gi->gradepass = min($passmark, (float)$gi->grademax);
            $DB->update_record('grade_items', $gi);
        }

        self::save_meta(
            $cmid,
            $courseid,
            $unitid,
            self::MODULE_QUIZ,
            $quizid,
            $assessmenttype,
            $passmark,
            $requirefullscreen ? 1 : 0,
            $maxwarnings,
            $warningaction
        );

        $transaction->allow_commit();
        return [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'quizid' => $quizid,
            'cmid' => $cmid,
            'name' => $name,
            'intro' => $introhtml,
            'gradeitemid' => $gradeitemid,
            'grademax' => $grademax,
            'passmark' => $passmark,
            'mapped' => $mapped,
            'assessmenttype' => $assessmenttype,
            'questioncount' => count($created),
            'timeopen' => $timeopen,
            'timeclose' => $timeclose,
            'timelimit' => $timelimit,
            'maxattempts' => $attempts,
            'requirefullscreen' => $requirefullscreen ? 1 : 0,
            'maxwarnings' => $maxwarnings,
            'warningaction' => $warningaction,
        ];
    }

    /**
     * @param array<string,mixed> $qdata
     */
    protected static function create_question(object $category, array $qdata, bool $shuffleanswers, int $fallbackindex): int {
        $type = strtolower(trim((string)($qdata['type'] ?? 'multichoice')));
        $typemap = [
            'multiplechoice' => 'multichoice',
            'mcq' => 'multichoice',
            'multiple_choice' => 'multichoice',
            'multipleselect' => 'multichoicemulti',
            'multiselect' => 'multichoicemulti',
            'multiple_select' => 'multichoicemulti',
            'truefalse' => 'truefalse',
            'true_false' => 'truefalse',
            'shortanswer' => 'shortanswer',
            'short_answer' => 'shortanswer',
            'essay' => 'essay',
            'fileupload' => 'fileupload',
            'file_upload' => 'fileupload',
        ];
        $type = $typemap[$type] ?? $type;

        $text = trim((string)($qdata['text'] ?? $qdata['questiontext'] ?? ''));
        if ($text === '') {
            throw new \invalid_parameter_exception("Question {$fallbackindex} has no text");
        }
        $html = self::to_html($text);
        $name = trim((string)($qdata['name'] ?? ''));
        if ($name === '') {
            $name = shorten_text(strip_tags($html), 80) ?: "Question {$fallbackindex}";
        }
        $mark = self::question_mark($qdata);
        $feedback = self::to_html((string)($qdata['explanation'] ?? $qdata['generalfeedback'] ?? ''));

        $fromform = new \stdClass();
        $fromform->category = $category->id . ',' . $category->contextid;
        $fromform->name = $name;
        $fromform->questiontext = ['text' => $html, 'format' => FORMAT_HTML];
        $fromform->generalfeedback = ['text' => $feedback, 'format' => FORMAT_HTML];
        $fromform->defaultmark = $mark;
        $fromform->penalty = 0;
        $fromform->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $qtypecode = $type === 'multichoicemulti' || $type === 'fileupload' ? ($type === 'fileupload' ? 'essay' : 'multichoice') : $type;
        $fromform->qtype = $qtypecode;

        if ($type === 'multichoice' || $type === 'multichoicemulti') {
            self::fill_multichoice_form($fromform, $qdata, $type === 'multichoice', $shuffleanswers);
        } else if ($type === 'truefalse') {
            self::fill_truefalse_form($fromform, $qdata);
        } else if ($type === 'shortanswer') {
            self::fill_shortanswer_form($fromform, $qdata);
        } else if ($type === 'essay' || $type === 'fileupload') {
            self::fill_essay_form($fromform, $type === 'fileupload');
        } else {
            throw new \invalid_parameter_exception("Unsupported question type: {$type}");
        }

        $qtype = \question_bank::get_qtype($qtypecode);
        $question = (object)['qtype' => $qtypecode];
        $saved = $qtype->save_question($question, $fromform);
        if (empty($saved->id)) {
            throw new \moodle_exception('quizcreationfailed', 'local_btiportal');
        }
        return (int)$saved->id;
    }

    protected static function fill_multichoice_form(object $fromform, array $qdata, bool $single, bool $shuffleanswers): void {
        $options = $qdata['options'] ?? $qdata['answers'] ?? [];
        if (!is_array($options) || count($options) < 2) {
            throw new \invalid_parameter_exception('Multiple choice questions need at least two options');
        }
        $correct = $qdata['correct'] ?? $qdata['correctanswer'] ?? $qdata['correctanswers'] ?? [];
        if (!is_array($correct)) {
            $correct = $correct === '' || $correct === null ? [] : [$correct];
        }
        $correctset = [];
        foreach ($correct as $c) {
            if ($c === '' || $c === null) {
                continue;
            }
            $correctset[] = strtoupper(trim((string)$c));
        }

        $fromform->single = $single ? 1 : 0;
        $fromform->shuffleanswers = $shuffleanswers ? 1 : 0;
        $fromform->answernumbering = 'ABCD';
        $fromform->showstandardinstruction = 0;
        $fromform->shownumcorrect = 0;
        $fromform->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $fromform->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $fromform->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];

        $fromform->answer = [];
        $fromform->fraction = [];
        $fromform->feedback = [];
        $letters = range('A', 'Z');
        $correctcount = 0;
        foreach (array_values($options) as $i => $opt) {
            if (is_array($opt)) {
                $label = trim((string)($opt['text'] ?? $opt['label'] ?? ''));
                $iskey = !empty($opt['correct']) || !empty($opt['iscorrect']);
            } else {
                $label = trim((string)$opt);
                $iskey = false;
            }
            if ($label === '') {
                continue;
            }
            $letter = $letters[$i] ?? (string)($i + 1);
            $match = $iskey
                || in_array($letter, $correctset, true)
                || in_array((string)$i, $correctset, true)
                || in_array(strtoupper($label), $correctset, true);
            if ($match) {
                $correctcount++;
            }
            $fromform->answer[] = ['text' => self::to_html($label), 'format' => FORMAT_HTML];
            $fromform->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
            $fromform->fraction[] = 0.0;
        }
        if (count($fromform->answer) < 2) {
            throw new \invalid_parameter_exception('Multiple choice questions need at least two options');
        }
        // Never invent a correct answer. If the teacher marked some, split the mark across them.
        if ($correctcount > 0) {
            $each = $single ? 1.0 : (1.0 / $correctcount);
            $seen = 0;
            foreach (array_values($options) as $i => $opt) {
                if (!isset($fromform->fraction[$i])) {
                    continue;
                }
                $label = is_array($opt) ? trim((string)($opt['text'] ?? $opt['label'] ?? '')) : trim((string)$opt);
                $letter = $letters[$i] ?? (string)($i + 1);
                $iskey = is_array($opt) && (!empty($opt['correct']) || !empty($opt['iscorrect']));
                $match = $iskey
                    || in_array($letter, $correctset, true)
                    || in_array((string)$i, $correctset, true)
                    || in_array(strtoupper($label), $correctset, true);
                if ($match) {
                    $fromform->fraction[$i] = $single ? 1.0 : $each;
                    $seen++;
                    if ($single) {
                        break;
                    }
                }
            }
        }
    }

    protected static function fill_truefalse_form(object $fromform, array $qdata): void {
        $raw = $qdata['correct'] ?? $qdata['correctanswer'] ?? null;
        $istrue = null;
        if (is_bool($raw)) {
            $istrue = $raw;
        } else if ($raw !== null && $raw !== '') {
            $v = strtolower(trim((string)$raw));
            if (in_array($v, ['true', 't', '1', 'yes'], true)) {
                $istrue = true;
            } else if (in_array($v, ['false', 'f', '0', 'no'], true)) {
                $istrue = false;
            }
        }
        if ($istrue === null) {
            throw new \invalid_parameter_exception('True/False questions require an explicit correct answer');
        }
        $fromform->correctanswer = $istrue ? 1 : 0;
        $fromform->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
        $fromform->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
    }

    protected static function fill_shortanswer_form(object $fromform, array $qdata): void {
        $expected = trim((string)($qdata['expected'] ?? $qdata['expectedanswer'] ?? $qdata['correct'] ?? $qdata['correctanswer'] ?? ''));
        if ($expected === '') {
            throw new \invalid_parameter_exception('Short answer questions require an expected answer');
        }
        $fromform->usecase = 0;
        $fromform->answer = [$expected];
        $fromform->fraction = [1.0];
        $fromform->feedback = [['text' => '', 'format' => FORMAT_HTML]];
    }

    protected static function fill_essay_form(object $fromform, bool $fileupload): void {
        $fromform->responseformat = $fileupload ? 'noinline' : 'editor';
        $fromform->responserequired = $fileupload ? 0 : 1;
        $fromform->responsefieldlines = 15;
        $fromform->attachments = $fileupload ? 5 : 0;
        $fromform->attachmentsrequired = $fileupload ? 1 : 0;
        $fromform->maxbytes = 0;
        $fromform->graderinfo = ['text' => '', 'format' => FORMAT_HTML];
        $fromform->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];
    }

    /** @param array<string,mixed> $qdata */
    protected static function question_mark(array $qdata): float {
        $mark = (float)($qdata['marks'] ?? $qdata['mark'] ?? $qdata['defaultmark'] ?? 1);
        return $mark > 0 ? $mark : 1.0;
    }

    protected static function to_html(string $value): string {
        $trim = trim($value);
        if ($trim === '') {
            return '<p></p>';
        }
        if (strpos($trim, '<') === false) {
            return '<p>' . nl2br(s($trim)) . '</p>';
        }
        return clean_text($trim, FORMAT_HTML);
    }

    public static function save_meta(
        int $cmid,
        int $courseid,
        int $unitid,
        string $module,
        int $instanceid,
        string $assessmenttype,
        float $passmark,
        int $requirefullscreen,
        int $maxwarnings,
        string $warningaction
    ): void {
        global $DB;
        $assessmenttype = self::normalize_type($assessmenttype);
        $warningaction = self::normalize_action($warningaction);
        $now = time();
        $existing = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cmid]);
        $record = (object)[
            'cmid' => $cmid,
            'courseid' => $courseid,
            'unitid' => $unitid,
            'module' => $module,
            'instanceid' => $instanceid,
            'assessmenttype' => $assessmenttype,
            'passmark' => $passmark,
            'requirefullscreen' => $requirefullscreen ? 1 : 0,
            'maxwarnings' => max(1, $maxwarnings),
            'warningaction' => $warningaction,
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_btiportal_assessment_meta', $record);
            return;
        }
        $record->timecreated = $now;
        $DB->insert_record('local_btiportal_assessment_meta', $record);
    }

    /**
     * @param list<int> $courseids
     * @return list<array<string,mixed>>
     */
    public static function list_for_courses(array $courseids, int $userid): array {
        global $DB;
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));

        // Permit an active student to see only their own programme-level Final Exam.
        // The Moodle programme course shortname must match the programme code.
        $programmeCourseId = 0;
        $profile = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid]);
        if ($profile
            && strtolower(trim((string)$profile->status)) === 'active'
            && strtolower(trim((string)$profile->admissionstatus)) === 'active'
            && strtolower(trim((string)$profile->academicstatus)) === 'active'
            && (int)$profile->programmeid > 0) {
            $programme = $DB->get_record(
                'local_btiportal_programme',
                ['id' => (int)$profile->programmeid],
                'id,code,name'
            );
            if ($programme && trim((string)$programme->code) !== '') {
                $programmeCourse = $DB->get_record(
                    'course',
                    ['shortname' => trim((string)$programme->code)],
                    'id,shortname,fullname,visible,category',
                    IGNORE_MISSING
                );
                if ($programmeCourse && (int)$programmeCourse->id > 1
                    && (int)$programmeCourse->visible === 1) {
                    $programmeCourseId = (int)$programmeCourse->id;
                    $courseids[] = $programmeCourseId;
                }
            }
        }

        $courseids = array_values(array_unique($courseids));
        if (empty($courseids)) {
            return [];
        }

        $out = [];
        foreach ($courseids as $courseid) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $canview = is_enrolled($context, $userid, '', true)
                || is_siteadmin($userid)
                || has_capability('moodle/course:view', $context)
                || has_capability('moodle/grade:viewall', $context);
            $isOwnProgrammeCourse = ($programmeCourseId > 0 && $courseid === $programmeCourseId);
            $isAssignedLecturerProgrammeCourse =
                lecturer_teaching_helper::user_has_active_programme_course_assignment(
                    $userid,
                    $courseid
                );
            $isAuthorizedProgrammeCourse =
                $isOwnProgrammeCourse || $isAssignedLecturerProgrammeCourse;

            if (!$canview && !$isAuthorizedProgrammeCourse) {
                continue;
            }

            $modinfo = get_fast_modinfo($courseid, $userid);
            $meta = $DB->get_records('local_btiportal_assessment_meta', ['courseid' => $courseid], '', '*', IGNORE_MISSING);
            $metabycmid = [];
            foreach ($meta as $row) {
                $metabycmid[(int)$row->cmid] = $row;
            }

            foreach ($modinfo->get_cms() as $cm) {
                if (!in_array($cm->modname, ['quiz', 'assign'], true)) {
                    continue;
                }
                if (!$cm->uservisible
                    && !has_capability('moodle/course:viewhiddenactivities', $context)
                    && !$isAuthorizedProgrammeCourse) {
                    continue;
                }
                $row = $metabycmid[(int)$cm->id] ?? null;

                if ($isAuthorizedProgrammeCourse) {
                    // Do not expose ordinary quizzes, assignments, or unit-level assessments
                    // from the programme container course.
                    if ($cm->modname !== 'quiz'
                        || !$row
                        || strtolower(trim((string)($row->assessmenttype ?? ''))) !== 'final'
                        || (int)($row->unitid ?? -1) !== 0
                        || (int)$cm->visible !== 1) {
                        continue;
                    }
                }

                $item = self::describe_cm($cm, $row);
                if ($item) {
                    if ($isAuthorizedProgrammeCourse) {
                        $item['programmeFinalAuthorized'] = 1;
                    }
                    $out[] = $item;
                }
            }
        }

        usort($out, static function ($a, $b) {
            $ao = (int)($a['timeopen'] ?? 0);
            $bo = (int)($b['timeopen'] ?? 0);
            if ($ao === $bo) {
                return strcmp((string)$a['name'], (string)$b['name']);
            }
            return $ao <=> $bo;
        });
        return $out;
    }

    /**
     * @param \cm_info $cm
     * @return array<string,mixed>|null
     */
    protected static function describe_cm(\cm_info $cm, ?object $meta): ?array {
        global $DB;
        $module = $cm->modname;
        $instanceid = (int)$cm->instance;
        $assessmenttype = $meta->assessmenttype ?? ($module === 'assign' ? self::TYPE_ASSIGNMENT : self::TYPE_QUIZ);
        $questioncount = 0;
        $grademax = 0.0;
        $timeopen = 0;
        $timeclose = 0;
        $timelimit = 0;
        $maxattempts = 1;
        $intro = '';

        if ($module === 'quiz') {
            $quiz = $DB->get_record('quiz', ['id' => $instanceid]);
            if (!$quiz) {
                return null;
            }
            $questioncount = (int)$DB->count_records('quiz_slots', ['quizid' => $instanceid]);
            $grademax = (float)$quiz->grade;
            $timeopen = (int)$quiz->timeopen;
            $timeclose = (int)$quiz->timeclose;
            $timelimit = (int)$quiz->timelimit;
            $maxattempts = (int)$quiz->attempts;
            $intro = (string)$quiz->intro;
        } else {
            $assign = $DB->get_record('assign', ['id' => $instanceid]);
            if (!$assign) {
                return null;
            }
            $grademax = (float)$assign->grade;
            $timeopen = (int)$assign->allowsubmissionsfromdate;
            $timeclose = (int)($assign->cutoffdate ?: $assign->duedate);
            $maxattempts = (int)$assign->maxattempts;
            $intro = (string)$assign->intro;
            if ($meta && !empty($meta->assessmenttype)) {
                $assessmenttype = $meta->assessmenttype;
            } else {
                $ameta = $DB->get_record('local_btiportal_assignment_meta', ['assignmentid' => $instanceid]);
                if ($ameta) {
                    $cat = strtolower((string)$ameta->category);
                    $assessmenttype = $cat === 'exam' ? self::TYPE_FINAL : ($cat === 'cat' ? self::TYPE_CAT : self::TYPE_ASSIGNMENT);
                }
            }
        }

        $assessmenttype = self::normalize_type((string)$assessmenttype);

        return [
            'cmid' => (int)$cm->id,
            'courseid' => (int)$cm->course,
            'unitid' => $module === self::MODULE_QUIZ && $assessmenttype === self::TYPE_FINAL
                ? 0
                : (int)$cm->section,
            'module' => $module,
            'instanceid' => $instanceid,
            'name' => (string)$cm->name,
            'intro' => $intro,
            'assessmenttype' => $assessmenttype,
            'visible' => $cm->visible ? 1 : 0,
            'uservisible' => $cm->uservisible ? 1 : 0,
            'grademax' => $grademax,
            'passmark' => $meta ? (float)$meta->passmark : 0,
            'questioncount' => $questioncount,
            'timeopen' => $timeopen,
            'timeclose' => $timeclose,
            'timelimit' => $timelimit,
            'maxattempts' => $maxattempts,
            'requirefullscreen' => $meta ? (int)$meta->requirefullscreen : 0,
            'maxwarnings' => $meta ? (int)$meta->maxwarnings : 3,
            'warningaction' => $meta->warningaction ?? self::ACTION_SUBMIT_FLAG,
            'section' => (int)$cm->sectionnum,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_one(int $cmid, int $userid): array {
        global $DB;
        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        $context = \context_course::instance((int)$cm->course);
        $canview = is_enrolled($context, $userid, '', true)
            || is_siteadmin($userid)
            || has_capability('moodle/course:view', $context);
        if (!$canview) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view assessment');
        }
        $modinfo = get_fast_modinfo((int)$cm->course, $userid);
        $cminfo = $modinfo->get_cm($cmid);
        $meta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cmid]);
        $item = self::describe_cm($cminfo, $meta ?: null);
        if (!$item) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        return $item;
    }

    public static function update_quiz_settings(
        int $cmid,
        int $userid,
        array $fields
    ): array {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        self::require_can_manage($userid, (int)$cm->course, (int)$cm->section, (int)$cm->id);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        $map = [
            'name' => 'name',
            'timeopen' => 'timeopen',
            'timeclose' => 'timeclose',
            'timelimit' => 'timelimit',
            'maxattempts' => 'attempts',
            'grademax' => 'grade',
        ];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $fields) && $fields[$in] !== null && $fields[$in] !== '') {
                if ($col === 'name') {
                    $quiz->$col = trim((string)$fields[$in]);
                } else {
                    $quiz->$col = (int)$fields[$in] === $fields[$in] || is_numeric($fields[$in])
                        ? (float)$fields[$in]
                        : $quiz->$col;
                    if (in_array($col, ['timeopen', 'timeclose', 'timelimit', 'attempts'], true)) {
                        $quiz->$col = (int)$fields[$in];
                    }
                }
            }
        }
        if (isset($fields['intro'])) {
            $quiz->intro = self::to_html((string)$fields['intro']);
            $quiz->introformat = FORMAT_HTML;
        }
        if (isset($fields['shuffleanswers'])) {
            $quiz->shuffleanswers = (int)$fields['shuffleanswers'] ? 1 : 0;
        }
        $quiz->timemodified = time();
        $DB->update_record('quiz', $quiz);
        if (isset($fields['shufflequestions'])) {
            $DB->set_field('quiz_sections', 'shufflequestions', ((int)$fields['shufflequestions']) ? 1 : 0, [
                'quizid' => $quiz->id,
            ]);
        }

        $meta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cmid]);
        if ($meta) {
            if (!empty($fields['assessmenttype'])) {
                $meta->assessmenttype = self::normalize_type((string)$fields['assessmenttype']);
            }
            if (isset($fields['passmark'])) {
                $meta->passmark = (float)$fields['passmark'];
            }
            if (isset($fields['requirefullscreen'])) {
                $meta->requirefullscreen = (int)$fields['requirefullscreen'] ? 1 : 0;
            }
            if (isset($fields['maxwarnings'])) {
                $meta->maxwarnings = max(1, (int)$fields['maxwarnings']);
            }
            if (!empty($fields['warningaction'])) {
                $meta->warningaction = self::normalize_action((string)$fields['warningaction']);
            }
            $meta->timemodified = time();
            $DB->update_record('local_btiportal_assessment_meta', $meta);
        }

        rebuild_course_cache((int)$cm->course, true);
        return self::get_one($cmid, $userid);
    }

    public static function set_visibility(int $cmid, int $userid, int $visible): array {
        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        self::require_can_manage($userid, (int)$cm->course, (int)$cm->section, (int)$cm->id);
        set_coursemodule_visible($cmid, $visible ? 1 : 0);
        rebuild_course_cache((int)$cm->course, true);
        return self::get_one($cmid, $userid);
    }

    public static function delete_assessment(int $cmid, int $userid): array {
        global $DB;
        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        self::require_can_manage($userid, (int)$cm->course, (int)$cm->section, (int)$cm->id);
        $courseid = (int)$cm->course;
        $instanceid = (int)$cm->instance;
        $module = $cm->modname;
        course_delete_module($cmid);
        $DB->delete_records('local_btiportal_assessment_meta', ['cmid' => $cmid]);
        if ($module === 'assign') {
            assignment_meta_helper::delete_for_assignment($instanceid);
        }
        if ($module === 'quiz') {
            $DB->delete_records('local_btiportal_exam_event', ['quizid' => $instanceid]);
            $DB->delete_records('local_btiportal_exam_flag', ['quizid' => $instanceid]);
        }
        rebuild_course_cache($courseid, true);
        return ['cmid' => $cmid, 'deleted' => true];
    }

    public static function duplicate_assessment(int $cmid, int $userid): array {
        global $DB;
        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        self::require_can_manage($userid, (int)$cm->course, (int)$cm->section, (int)$cm->id);
        $course = get_course((int)$cm->course);
        $newcm = duplicate_module($course, $cm);
        $newcmid = (int)($newcm->id ?? 0);
        if ($newcmid <= 0) {
            throw new \moodle_exception('error', 'error', '', null, 'Moodle could not duplicate this activity');
        }
        $srcmeta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cmid]);
        if ($srcmeta) {
            unset($srcmeta->id);
            $srcmeta->cmid = $newcmid;
            $srcmeta->instanceid = (int)$newcm->instance;
            $srcmeta->timecreated = time();
            $srcmeta->timemodified = time();
            $DB->insert_record('local_btiportal_assessment_meta', $srcmeta);
        }
        return self::get_one($newcmid, $userid);
    }

    /**
     * Teacher preview of quiz questions (includes correct answers).
     *
     * @return list<array<string,mixed>>
     */
    public static function get_quiz_questions_for_teacher(int $cmid, int $userid): array {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        self::require_can_manage($userid, (int)$cm->course, (int)$cm->section, (int)$cm->id);

        $slots = $DB->get_records('quiz_slots', ['quizid' => $cm->instance], 'slot ASC');
        $out = [];
        foreach ($slots as $slot) {
            $qid = self::question_id_for_slot((int)$slot->id, (int)$cm->id);
            if ($qid <= 0) {
                continue;
            }
            $question = \question_bank::load_question_data($qid);
            $out[] = self::export_question_for_teacher($slot, $question);
        }
        return $out;
    }

    /**
     * Structured questions for the current user's attempt. Never includes correct answers.
     *
     * @return array<string,mixed>
     */
    public static function get_attempt_questions(int $attemptid, int $userid): array {
        global $DB;
        $attemptobj = quiz_attempt::create($attemptid);
        $owner = (int)$attemptobj->get_userid();
        $context = $attemptobj->get_context();
        if ($owner !== $userid && !has_capability('mod/quiz:viewreports', $context) && !is_siteadmin($userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view attempt');
        }

        $quiz = $attemptobj->get_quiz();
        $cmid = (int)$attemptobj->get_cmid();
        $meta = $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cmid]);
        $flag = $DB->get_record('local_btiportal_exam_flag', ['attemptid' => $attemptid]);
        $endtime = 0;
        try {
            $due = $attemptobj->get_due_date();
            $endtime = $due ? (int)$due : 0;
        } catch (\Throwable $e) {
            $endtime = 0;
        }

        $questions = [];
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $question = $qa->get_question();
            $qtype = $question->get_type_name();
            $prefix = $qa->get_field_prefix();
            $item = [
                'slot' => (int)$slot,
                'type' => $qtype,
                'name' => (string)$question->name,
                'questiontext' => (string)$question->questiontext,
                'maxmark' => (float)$qa->get_max_mark(),
                'status' => (string)$qa->get_state_class(true),
                'answered' => $qa->get_state() !== \question_state::$todo,
                'flagged' => $qa->is_flagged() ? 1 : 0,
                'prefix' => $prefix,
                'sequencecheck' => (int)$qa->get_sequence_check_count(),
                'sequencecheckname' => $prefix . ':sequencecheck',
                'options' => [],
                'inputname' => $prefix . 'answer',
                'inputvalue' => (string)$qa->get_last_qt_var('answer', ''),
                'requiresfile' => 0,
            ];

            if ($qtype === 'multichoice' && method_exists($question, 'get_order')) {
                $order = $question->get_order($qa);
                $single = !($question instanceof \qtype_multichoice_multi_question);
                $item['type'] = $single ? 'multichoice' : 'multichoicemulti';
                $selected = $qa->get_last_qt_var('answer', null);
                foreach ($order as $key => $ansid) {
                    $ans = $question->answers[$ansid] ?? null;
                    if (!$ans) {
                        continue;
                    }
                    $choicekey = 'choice' . $key;
                    $item['options'][] = [
                        'id' => (int)$ansid,
                        'label' => (string)$ans->answer,
                        'value' => (string)$ansid,
                        'fieldname' => $single ? $prefix . 'answer' : $prefix . $choicekey,
                        'selected' => $single
                            ? ((string)$selected === (string)$ansid ? 1 : 0)
                            : ((int)$qa->get_last_qt_var($choicekey, 0) ? 1 : 0),
                    ];
                }
                if (!$single) {
                    $item['inputname'] = '';
                    $item['inputvalue'] = '';
                }
            } else if ($qtype === 'truefalse') {
                $selected = (string)$qa->get_last_qt_var('answer', '');
                $item['options'] = [
                    [
                        'id' => 1,
                        'label' => 'True',
                        'value' => '1',
                        'fieldname' => $prefix . 'answer',
                        'selected' => $selected === '1' ? 1 : 0,
                    ],
                    [
                        'id' => 0,
                        'label' => 'False',
                        'value' => '0',
                        'fieldname' => $prefix . 'answer',
                        'selected' => $selected === '0' ? 1 : 0,
                    ],
                ];
            } else if ($qtype === 'essay') {
                $item['requiresfile'] = !empty($question->attachments) ? 1 : 0;
                $item['inputvalue'] = (string)$qa->get_last_qt_var('answer', '');
            }

            $questions[] = $item;
        }

        $state = (string)$attemptobj->get_state();
        return [
            'attemptid' => $attemptid,
            'quizid' => (int)$quiz->id,
            'cmid' => $cmid,
            'userid' => $owner,
            'state' => $state,
            'timestart' => (int)$attemptobj->get_attempt()->timestart,
            'endtime' => $endtime,
            'timelimit' => (int)$quiz->timelimit,
            'timeopen' => (int)$quiz->timeopen,
            'timeclose' => (int)$quiz->timeclose,
            'currentpage' => (int)$attemptobj->get_currentpage(),
            'requirefullscreen' => $meta ? (int)$meta->requirefullscreen : 0,
            'maxwarnings' => $meta ? (int)$meta->maxwarnings : 3,
            'warningaction' => $meta->warningaction ?? self::ACTION_SUBMIT_FLAG,
            'warningcount' => $flag ? (int)$flag->warningcount : 0,
            'flagged' => $flag ? (int)$flag->flagged : 0,
            'autosubmitted' => $flag ? (int)$flag->autosubmitted : 0,
            'layout' => (string)$attemptobj->get_attempt()->layout,
            'questions' => $questions,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_results(int $cmid, int $userid): array {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        self::require_can_manage($userid, (int)$cm->course, (int)$cm->section, (int)$cm->id);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_course::instance((int)$cm->course);
        $students = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id, u.firstname, u.lastname, u.email');
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'preview' => 0], 'userid ASC, attempt ASC');
        $grades = $DB->get_records('quiz_grades', ['quiz' => $quiz->id], '', 'userid, grade');
        $flags = $DB->get_records('local_btiportal_exam_flag', ['quizid' => $quiz->id]);
        $flagbyattempt = [];
        foreach ($flags as $f) {
            $flagbyattempt[(int)$f->attemptid] = $f;
        }

        $attemptsbyuser = [];
        foreach ($attempts as $a) {
            $attemptsbyuser[(int)$a->userid][] = $a;
        }

        $rows = [];
        $submitted = 0;
        $inprogress = 0;
        $notstarted = 0;
        $scores = [];
        foreach ($students as $student) {
            $uid = (int)$student->id;
            $userattempts = $attemptsbyuser[$uid] ?? [];
            $latest = $userattempts ? end($userattempts) : null;
            $grade = isset($grades[$uid]) ? (float)$grades[$uid]->grade : null;
            $flag = $latest ? ($flagbyattempt[(int)$latest->id] ?? null) : null;
            $state = 'notstarted';
            if ($latest) {
                if ($latest->state === quiz_attempt::FINISHED) {
                    $state = 'submitted';
                    $submitted++;
                } else {
                    $state = 'inprogress';
                    $inprogress++;
                }
            } else {
                $notstarted++;
            }
            if ($flag && (int)$flag->flagged) {
                $state = 'flagged';
            } else if ($flag && (int)$flag->warningcount > 0 && $state !== 'notstarted') {
                $state = $state === 'submitted' ? 'warning' : $state;
            }
            if ($grade !== null) {
                $scores[] = $grade;
            }
            $rows[] = [
                'userid' => $uid,
                'fullname' => fullname($student),
                'email' => (string)$student->email,
                'attemptid' => $latest ? (int)$latest->id : 0,
                'attemptnumber' => $latest ? (int)$latest->attempt : 0,
                'state' => $state,
                'quizstate' => $latest ? (string)$latest->state : '',
                'score' => $grade === null ? -1 : $grade,
                'grademax' => (float)$quiz->grade,
                'percent' => ($grade !== null && (float)$quiz->grade > 0)
                    ? round(($grade / (float)$quiz->grade) * 100, 1)
                    : -1,
                'timefinish' => $latest ? (int)$latest->timefinish : 0,
                'timestart' => $latest ? (int)$latest->timestart : 0,
                'warningcount' => $flag ? (int)$flag->warningcount : 0,
                'flagged' => $flag ? (int)$flag->flagged : 0,
                'autosubmitted' => $flag ? (int)$flag->autosubmitted : 0,
            ];
        }

        $avg = $scores ? array_sum($scores) / count($scores) : 0;
        $maxgrade = (float)$quiz->grade;
        return [
            'cmid' => $cmid,
            'quizid' => (int)$quiz->id,
            'name' => (string)$quiz->name,
            'grademax' => $maxgrade,
            'students' => count($students),
            'submitted' => $submitted,
            'inprogress' => $inprogress,
            'notstarted' => $notstarted,
            'average' => $maxgrade > 0 && $scores ? round(($avg / $maxgrade) * 100, 1) : 0,
            'highest' => $maxgrade > 0 && $scores ? round((max($scores) / $maxgrade) * 100, 1) : 0,
            'lowest' => $maxgrade > 0 && $scores ? round((min($scores) / $maxgrade) * 100, 1) : 0,
            'attempts' => $rows,
        ];
    }

    public static function require_can_manage(
        int $userid,
        int $courseid,
        int $sectionid,
        ?int $cmid = null
    ): void {
        global $DB;

        $context = \context_course::instance($courseid);
        if (is_siteadmin($userid)) {
            return;
        }
        $meta = $cmid ? $DB->get_record('local_btiportal_assessment_meta', ['cmid' => $cmid]) : false;
        $isfinal = $meta
            && self::normalize_type((string)$meta->assessmenttype) === self::TYPE_FINAL;
        $isassignedteacher = $isfinal
            ? lecturer_teaching_helper::user_teaches_moodle_course($userid, $courseid)
            : gradebook_helper::user_is_unit_teacher($userid, $courseid, $sectionid);
        if ($isassignedteacher) {
            if (has_capability('moodle/course:manageactivities', $context)
                || has_capability('mod/quiz:manage', $context)
                || has_capability('mod/quiz:addinstance', $context)
                || has_capability('mod/assign:addinstance', $context)
            ) {
                return;
            }
        }
        throw new \moodle_exception('nopermissions', 'error', '', 'manage assessment');
    }

    protected static function question_id_for_slot(int $slotid, int $cmid): int {
        global $DB;
        $modcontext = \context_module::instance($cmid);
        $sql = "SELECT qv.questionid
                  FROM {question_references} qr
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                 WHERE qr.itemid = :slotid
                   AND qr.component = :component
                   AND qr.questionarea = :area
                   AND qr.usingcontextid = :ctx
              ORDER BY qv.version DESC";
        $qid = $DB->get_field_sql($sql, [
            'slotid' => $slotid,
            'component' => 'mod_quiz',
            'area' => 'slot',
            'ctx' => $modcontext->id,
        ], IGNORE_MULTIPLE);
        return (int)$qid;
    }

    /**
     * @return array<string,mixed>
     */
    protected static function export_question_for_teacher(object $slot, object $question): array {
        $type = (string)$question->qtype;
        $options = [];
        $expected = '';
        if (!empty($question->options->answers) && is_array($question->options->answers)) {
            $answers = $question->options->answers;
        } else {
            $answers = $question->options->answers ?? [];
        }
        if (!empty($question->options) && !empty($question->options->answers)) {
            foreach ($question->options->answers as $ans) {
                $frac = (float)($ans->fraction ?? 0);
                $options[] = [
                    'label' => (string)$ans->answer,
                    'fraction' => $frac,
                    'iscorrect' => $frac > 0 ? 1 : 0,
                ];
                if ($frac > 0 && $expected === '') {
                    $expected = strip_tags((string)$ans->answer);
                }
            }
        }
        return [
            'slot' => (int)$slot->slot,
            'page' => (int)$slot->page,
            'questionid' => (int)$question->id,
            'type' => $type,
            'name' => (string)$question->name,
            'questiontext' => (string)$question->questiontext,
            'maxmark' => (float)$slot->maxmark,
            'options' => $options,
            'expectedanswer' => $expected,
            'generalfeedback' => (string)($question->generalfeedback ?? ''),
        ];
    }
}
