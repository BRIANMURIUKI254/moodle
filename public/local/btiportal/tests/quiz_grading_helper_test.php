<?php
defined('MOODLE_INTERNAL') || die();

use local_btiportal\assessment_helper;
use local_btiportal\exam_monitor_helper;
use local_btiportal\external\get_quiz_attempt_for_grading;
use local_btiportal\external\get_quiz_grading_queue;
use local_btiportal\external\save_quiz_manual_grade;
use local_btiportal\quiz_reopen_helper;
use mod_quiz\quiz_settings;

/**
 * Regression coverage for Moodle-native quiz manual grading.
 *
 * @package local_btiportal
 * @covers \local_btiportal\quiz_grading_helper
 */
final class local_btiportal_quiz_grading_helper_test extends advanced_testcase {
    /** @var stdClass */
    private $course;
    /** @var stdClass */
    private $teacher;
    /** @var stdClass */
    private $otherteacher;
    /** @var stdClass */
    private $student;
    /** @var stdClass */
    private $otherstudent;
    /** @var stdClass */
    private $unit;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        global $DB;
        $this->course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->otherteacher = $this->getDataGenerator()->create_user();
        $this->student = $this->getDataGenerator()->create_user();
        $this->otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->otherteacher->id, $othercourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->otherstudent->id, $this->course->id, 'student');

        course_create_sections_if_missing($this->course->id, 1);
        $sections = $DB->get_records('course_sections', ['course' => $this->course->id], 'section ASC');
        $this->unit = array_values(array_filter(
            $sections,
            static fn($section) => (int)$section->section > 0
        ))[0];
        $DB->insert_record('local_btiportal_unit', (object)[
            'courseid' => $this->course->id,
            'sectionid' => $this->unit->id,
            'teacheruserid' => $this->teacher->id,
            'timecreated' => time(),
        ]);
    }

    /**
     * Create an assessment with one or more manually gradable essay questions and an optional auto question.
     *
     * @return stdClass quiz record
     */
    private function create_quiz(string $type = 'quiz', int $essaycount = 1, bool $auto = false): stdClass {
        global $DB;

        $final = $type === 'final';
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'section' => $final ? 0 : (int)$this->unit->section,
            'name' => $final ? 'Final Exam grading test' : 'Unit quiz grading test',
            'grade' => 100,
            'attempts' => 1,
        ]);
        $cmid = (int)$DB->get_field('course_modules', 'id', [
            'course' => $this->course->id,
            'instance' => $quiz->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'quiz']),
        ], MUST_EXIST);
        $modcontext = \context_module::instance($cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $modcontext->id]);
        for ($index = 1; $index <= $essaycount; $index++) {
            $question = $questiongenerator->create_question('essay', null, [
                'category' => $category->id,
                'defaultmark' => 5,
                'name' => 'Essay question ' . $index,
                'questiontext' => 'Explain the grading test ' . $index,
            ]);
            quiz_add_quiz_question($question->id, $quiz, 0, 5);
        }
        if ($auto) {
            $question = $questiongenerator->create_question('truefalse', null, [
                'category' => $category->id,
                'defaultmark' => 1,
                'name' => 'Automatically graded question',
            ]);
            quiz_add_quiz_question($question->id, $quiz, 0, 1);
        }

        $settings = quiz_settings::create((int)$quiz->id);
        $settings->get_grade_calculator()->recompute_quiz_sumgrades();
        assessment_helper::save_meta(
            $cmid,
            (int)$this->course->id,
            $final ? 0 : (int)$this->unit->id,
            'quiz',
            (int)$quiz->id,
            $final ? 'final' : 'quiz',
            0.0,
            0,
            3,
            'flag'
        );
        return $quiz;
    }

    /**
     * Start a real Moodle attempt, submit essay text and optionally finish it.
     *
     * @return int Attempt id
     */
    private function create_attempt(stdClass $quiz, bool $finished = true, int $attemptnumber = 1): int {
        $quizobj = quiz_settings::create((int)$quiz->id, (int)$this->student->id);
        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $usage->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, $attemptnumber, null, $timenow, false, (int)$this->student->id);
        quiz_start_new_attempt($quizobj, $usage, $attempt, $attemptnumber, $timenow, [], []);
        quiz_attempt_save_started($quizobj, $usage, $attempt);

        $attemptobj = \mod_quiz\quiz_attempt::create((int)$attempt->id);
        $response = [];
        foreach ($attemptobj->get_question_usage()->get_slots() as $slot) {
            $questionattempt = $attemptobj->get_question_attempt($slot);
            $question = $questionattempt->get_question();
            if ($question->get_type_name() === 'essay') {
                $response[$questionattempt->get_field_prefix() . 'answer'] = 'Original student response';
            } else if ($question->get_type_name() === 'truefalse') {
                $correctresponse = $question->get_correct_response();
                $response[$questionattempt->get_field_prefix() . 'answer'] =
                    !empty($correctresponse['answer']) ? 1 : 0;
            }
        }
        if ($response) {
            $attemptobj->process_submitted_actions($timenow, false, $response);
        }
        if ($finished) {
            $attemptobj->process_submit($timenow, false);
            $attemptobj->process_grade_submission($timenow);
        }
        return (int)$attempt->id;
    }

    private function get_attempt(int $attemptid, stdClass $quiz): array {
        return get_quiz_attempt_for_grading::execute(
            $attemptid,
            (int)$this->course->id,
            (int)$quiz->id
        );
    }

    private function grade(int $attemptid, stdClass $quiz, int $slot, float $mark, string $feedback): array {
        return save_quiz_manual_grade::execute(
            $attemptid,
            (int)$this->course->id,
            (int)$quiz->id,
            $slot,
            $mark,
            $feedback
        );
    }

    public function test_assigned_course_teacher_can_access_submitted_final_exam(): void {
        global $DB;
        $this->setUser($this->teacher);
        $DB->delete_records('local_btiportal_unit', [
            'courseid' => $this->course->id,
            'teacheruserid' => $this->teacher->id,
        ]);
        $quiz = $this->create_quiz('final');
        $attemptid = $this->create_attempt($quiz);

        $attempt = $this->get_attempt($attemptid, $quiz);
        $this->assertSame(0, $attempt['unitid']);
        $this->assertSame('needsgrading', $attempt['questions'][0]['gradingstate']);
        $queue = get_quiz_grading_queue::execute();
        $this->assertCount(1, $queue);
        $this->assertSame($attemptid, $queue[0]['attemptid']);
        $this->assertSame(0, $queue[0]['unitid']);
    }

    public function test_assigned_unit_teacher_can_access_submitted_unit_quiz(): void {
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz();
        $attemptid = $this->create_attempt($quiz);

        $attempt = $this->get_attempt($attemptid, $quiz);
        $this->assertSame((int)$this->unit->id, $attempt['unitid']);
        $this->assertSame('Original student response', $attempt['questions'][0]['response']);
    }

    public function test_unrelated_teacher_cannot_access_or_grade_attempt(): void {
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz();
        $attemptid = $this->create_attempt($quiz);
        $this->setUser($this->otherteacher);

        try {
            $this->get_attempt($attemptid, $quiz);
            $this->fail('An unrelated teacher must not access the attempt.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('notunitteacher', $exception->errorcode);
        }
        try {
            $this->grade($attemptid, $quiz, 1, 2.0, 'Unauthorized');
            $this->fail('An unrelated teacher must not grade the attempt.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('notunitteacher', $exception->errorcode);
        }
    }

    public function test_student_cannot_grade_another_students_attempt(): void {
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz();
        $attemptid = $this->create_attempt($quiz);
        $this->setUser($this->otherstudent);

        $this->expectException(\moodle_exception::class);
        $this->grade($attemptid, $quiz, 1, 2.0, 'Not authorized');
    }

    public function test_attempt_in_progress_cannot_be_manually_graded(): void {
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz();
        $attemptid = $this->create_attempt($quiz, false);

        $this->expectException(\invalid_parameter_exception::class);
        $this->grade($attemptid, $quiz, 1, 2.0, 'Not submitted');
    }

    public function test_invalid_question_slot_and_marks_are_rejected(): void {
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz();
        $attemptid = $this->create_attempt($quiz);

        try {
            $this->grade($attemptid, $quiz, 99, 2.0, 'Invalid slot');
            $this->fail('An invalid slot must be rejected.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('slot', $exception->getMessage());
        }
        foreach ([-0.1, 5.1, INF, NAN] as $mark) {
            try {
                $this->grade($attemptid, $quiz, 1, $mark, 'Invalid mark');
                $this->fail('An invalid mark must be rejected.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString('mark', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_manual_mark_feedback_response_and_quiz_grade_are_persisted(): void {
        global $DB;
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz();
        $attemptid = $this->create_attempt($quiz);
        $before = $this->get_attempt($attemptid, $quiz);
        $result = $this->grade($attemptid, $quiz, 1, 4.0, 'Good explanation');

        $after = $this->get_attempt($attemptid, $quiz);
        $this->assertSame(4.0, $after['questions'][0]['currentmark']);
        $this->assertSame('Good explanation', $after['questions'][0]['feedback']);
        $this->assertSame($before['questions'][0]['response'], $after['questions'][0]['response']);
        $this->assertEquals(4.0, (float)$DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attemptid]));
        $this->assertEquals(80.0, (float)$DB->get_field('quiz_grades', 'grade', [
            'quiz' => $quiz->id,
            'userid' => $this->student->id,
        ]));
        $this->assertSame(4.0, $result['mark']);
        $this->assertSame(4.0, $result['attemptsumgrades']);
        $this->assertSame(80.0, $result['quizgrade']);
        $review = $this->get_attempt($attemptid, $quiz);
        $this->assertSame('manuallygraded', $review['questions'][0]['gradingstate']);
        $this->assertSame('Original student response', $review['questions'][0]['response']);
        $this->assertSame('Good explanation', $review['questions'][0]['feedback']);
    }

    public function test_reopened_student_uses_the_retaken_attempt_grade_and_integrity_overrides(): void {
        global $DB;
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz();
        $firstattemptid = $this->create_attempt($quiz);
        $this->grade($firstattemptid, $quiz, 1, 5.0, 'First attempt');

        $now = time();
        $settings = quiz_reopen_helper::reopen_for_student([
            'quizid' => (int)$quiz->id,
            'courseid' => (int)$this->course->id,
            'userid' => (int)$this->student->id,
            'timeopen' => $now - 60,
            'timeclose' => $now + 3600,
            'timelimit' => 1800,
            'attempts' => 2,
            'requirefullscreen' => 1,
            'maxwarnings' => 2,
            'warningaction' => 'submit_flag',
            'flagged' => 1,
            'warningcount' => 1,
        ], (int)$this->teacher->id);

        $this->assertSame(2, $settings['attempts']);
        $this->assertSame(1, $settings['requirefullscreen']);
        $this->assertSame(2, $settings['maxwarnings']);
        $this->assertSame('submit_flag', $settings['warningaction']);
        $this->assertSame(1, $settings['flagged']);
        $this->assertSame(1, $settings['warningcount']);
        $this->assertSame(1, $settings['latestgrade']);

        $secondattemptid = $this->create_attempt($quiz, true, 2);
        exam_monitor_helper::log_event(
            (int)$this->teacher->id,
            (int)$quiz->id,
            $secondattemptid,
            'EXAM_STARTED'
        );
        $secondflag = $DB->get_record('local_btiportal_exam_flag', ['attemptid' => $secondattemptid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$secondflag->flagged);
        $this->assertEquals(100.0, (float)$DB->get_field('quiz_grades', 'grade', [
            'quiz' => $quiz->id,
            'userid' => $this->student->id,
        ]));
        $this->grade($secondattemptid, $quiz, 1, 1.0, 'Retake grade');

        $this->assertEquals(20.0, (float)$DB->get_field('quiz_grades', 'grade', [
            'quiz' => $quiz->id,
            'userid' => $this->student->id,
        ]));
        $override = $DB->get_record('quiz_overrides', [
            'quiz' => $quiz->id,
            'userid' => $this->student->id,
        ], '*', MUST_EXIST);
        $this->assertSame($now + 3600, (int)$override->timeclose);
        $this->assertSame(2, (int)$override->attempts);
    }

    public function test_queue_includes_auto_pending_partial_and_fully_graded_attempts(): void {
        $this->setUser($this->teacher);
        $automaticquiz = $this->create_quiz('quiz', 0, true);
        $automaticattemptid = $this->create_attempt($automaticquiz);
        $quiz = $this->create_quiz('quiz', 2, true);
        $attemptid = $this->create_attempt($quiz);

        $queue = get_quiz_grading_queue::execute();
        $this->assertCount(2, $queue);
        $queuebyattempt = array_column($queue, null, 'attemptid');
        $this->assertSame(0, $queuebyattempt[$automaticattemptid]['pendingcount']);
        $this->assertSame(1, $queuebyattempt[$automaticattemptid]['autogradedcount']);
        $this->assertSame(2, $queuebyattempt[$attemptid]['pendingcount']);
        $this->assertSame(1, $queuebyattempt[$attemptid]['autogradedcount']);

        $this->grade($attemptid, $quiz, 1, 3.0, 'First marked');
        $queue = get_quiz_grading_queue::execute();
        $this->assertCount(2, $queue);
        $queuebyattempt = array_column($queue, null, 'attemptid');
        $this->assertSame(1, $queuebyattempt[$attemptid]['pendingcount']);
        $this->assertSame(1, $queuebyattempt[$attemptid]['manuallygradedcount']);

        $this->grade($attemptid, $quiz, 2, 4.0, 'Second marked');
        $queue = get_quiz_grading_queue::execute();
        $this->assertCount(2, $queue);
        $queuebyattempt = array_column($queue, null, 'attemptid');
        $this->assertSame(0, $queuebyattempt[$attemptid]['pendingcount']);
        $this->assertSame(2, $queuebyattempt[$attemptid]['manuallygradedcount']);
        $this->assertGreaterThanOrEqual(0, $queuebyattempt[$attemptid]['overallgrade']);
    }

    public function test_queue_excludes_in_progress_and_preview_attempts(): void {
        global $DB;
        $this->setUser($this->teacher);
        $inprogressquiz = $this->create_quiz();
        $this->create_attempt($inprogressquiz, false);
        $previewquiz = $this->create_quiz();
        $previewattemptid = $this->create_attempt($previewquiz);
        $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $previewattemptid]);

        $this->assertSame([], get_quiz_grading_queue::execute());
    }

    public function test_automatically_graded_questions_are_not_manually_gradable(): void {
        $this->setUser($this->teacher);
        $quiz = $this->create_quiz('quiz', 0, true);
        $attemptid = $this->create_attempt($quiz);
        $attempt = $this->get_attempt($attemptid, $quiz);

        $this->assertSame('autograded', $attempt['questions'][0]['gradingstate']);
        $this->assertFalse($attempt['questions'][0]['eligibleformanualgrading']);
        $this->expectException(\invalid_parameter_exception::class);
        $this->grade($attemptid, $quiz, 1, 1.0, 'Must not overwrite automatic mark');
    }
}
