<?php
defined('MOODLE_INTERNAL') || die();

use local_btiportal\assessment_helper;
use local_btiportal\academic_auth_helper;
use local_btiportal\external\get_my_gradebook;
use local_btiportal\gradebook_helper;
use local_btiportal\lecturer_teaching_helper;

/**
 * Student gradebook privacy, visibility and assessment metadata tests.
 *
 * @package local_btiportal
 * @covers \local_btiportal\student_gradebook_helper
 * @covers \local_btiportal\external\get_my_gradebook
 */
final class local_btiportal_student_gradebook_test extends advanced_testcase {
    /** @var stdClass */
    private $course;
    /** @var stdClass */
    private $student;
    /** @var stdClass */
    private $otherstudent;
    /** @var stdClass */
    private $unit;
    /** @var stdClass */
    private $catassignment;
    /** @var stdClass */
    private $assignment;
    /** @var stdClass */
    private $unitquiz;
    /** @var stdClass */
    private $regularquiz;
    /** @var stdClass */
    private $finalquiz;
    /** @var int */
    private $catgradeitemid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        global $DB;
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->otherstudent->id, $this->course->id, 'student');

        course_create_sections_if_missing($this->course->id, 1);
        $sections = $DB->get_records('course_sections', ['course' => $this->course->id], 'section ASC');
        $topicsections = array_values(array_filter(
            $sections,
            static fn($section) => (int)$section->section > 0
        ));
        $this->unit = $topicsections[0];

        $this->catassignment = $this->create_assignment('CAT assessment', 'CAT');
        $this->assignment = $this->create_assignment('Assignment assessment', 'ASSIGNMENT');
        $this->regularquiz = $this->create_quiz('quiz', (int)$this->unit->id);
        $this->unitquiz = $this->create_quiz('midterm', (int)$this->unit->id);
        $this->finalquiz = $this->create_quiz('final', 0);

        $this->setUser($this->student);
    }

    /**
     * Create an assignment with explicit BTI classification and grade mapping.
     */
    private function create_assignment(string $name, string $category): stdClass {
        global $DB;

        $assignment = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id,
            'section' => (int)$this->unit->section,
            'name' => $name,
            'grade' => 100,
        ]);
        $gradeitemid = (int)$DB->get_field('grade_items', 'id', [
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignment->id,
            'itemnumber' => 0,
        ], MUST_EXIST);
        gradebook_helper::map_grade_item((int)$this->course->id, (int)$this->unit->id, $gradeitemid);
        \local_btiportal\assignment_meta_helper::save(
            (int)$assignment->id,
            (int)$this->course->id,
            (int)$this->unit->id,
            $category
        );
        if ($category === 'CAT') {
            $this->catgradeitemid = $gradeitemid;
        }

        return $assignment;
    }

    /**
     * Create a quiz with BTI metadata; finals intentionally have no unit mapping.
     */
    private function create_quiz(string $type, int $unitid): stdClass {
        global $DB;

        $final = $type === assessment_helper::TYPE_FINAL;
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'section' => $final ? 0 : (int)$this->unit->section,
            'name' => $final ? 'Course Final Exam' : 'Unit examination',
            'grade' => 100,
        ]);
        $cmid = (int)$DB->get_field('course_modules', 'id', [
            'course' => $this->course->id,
            'instance' => $quiz->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'quiz']),
        ], MUST_EXIST);
        $gradeitemid = (int)$DB->get_field('grade_items', 'id', [
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ], MUST_EXIST);
        if (!$final) {
            gradebook_helper::map_grade_item((int)$this->course->id, $unitid, $gradeitemid);
        }
        assessment_helper::save_meta(
            $cmid,
            (int)$this->course->id,
            $unitid,
            assessment_helper::MODULE_QUIZ,
            (int)$quiz->id,
            $type,
            0.0,
            0,
            3,
            assessment_helper::ACTION_SUBMIT
        );

        return $quiz;
    }

    /**
     * Return a row for an assessment instance, if Moodle currently exposes it.
     *
     * @param array $response Endpoint response
     * @param int $assessmentid Activity instance id
     * @return array|null
     */
    private function find_grade(array $response, int $assessmentid): ?array {
        foreach ($response['grades'] as $grade) {
            if ($grade['assessmentid'] === $assessmentid) {
                return $grade;
            }
        }
        return null;
    }

    public function test_returns_only_authenticated_students_visible_grades(): void {
        global $DB;

        $gradeitem = \grade_item::fetch(['id' => $this->catgradeitemid]);
        $gradeitem->update_final_grade(
            (int)$this->student->id,
            81.0,
            'student gradebook test',
            'Teacher feedback'
        );
        $gradeitem->update_final_grade((int)$this->otherstudent->id, 24.0, 'student gradebook test');

        $response = get_my_gradebook::execute();
        $grade = $this->find_grade($response, (int)$this->catassignment->id);

        $this->assertNotNull($grade);
        $this->assertSame(81.0, $grade['score']);
        $this->assertSame((int)$this->unit->id, $grade['unitid']);
        $this->assertSame('Teacher feedback', $grade['feedback']);
        $this->assertArrayNotHasKey('studentid', $grade);
        $this->assertArrayNotHasKey('userid', $grade);
        $this->assertSame([], get_my_gradebook::execute_parameters()->keys);
        $this->assertTrue($DB->record_exists('grade_grades', [
            'itemid' => $this->catgradeitemid,
            'userid' => $this->otherstudent->id,
        ]));
    }

    public function test_caller_cannot_resolve_another_students_identity(): void {
        $this->expectException(\moodle_exception::class);
        academic_auth_helper::resolve_student_userid_for_read((int)$this->otherstudent->id);
    }

    public function test_guest_request_is_rejected(): void {
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        get_my_gradebook::execute();
    }

    public function test_hidden_grade_is_not_returned(): void {
        $gradeitem = \grade_item::fetch(['id' => $this->catgradeitemid]);
        $gradeitem->update_final_grade((int)$this->student->id, 91.0, 'hidden grade test');
        $grade = \grade_grade::fetch([
            'itemid' => $this->catgradeitemid,
            'userid' => $this->student->id,
        ]);
        $grade->set_hidden(1);

        $response = get_my_gradebook::execute();

        $this->assertNull($this->find_grade($response, (int)$this->catassignment->id));
    }

    public function test_hidden_by_date_grade_does_not_expose_raw_value(): void {
        global $CFG;

        $CFG->grade_hiddenasdate = 1;
        $gradeitem = \grade_item::fetch(['id' => $this->catgradeitemid]);
        $gradeitem->update_final_grade((int)$this->student->id, 87.0, 'hidden by date test');
        $grade = \grade_grade::fetch([
            'itemid' => $this->catgradeitemid,
            'userid' => $this->student->id,
        ]);
        $grade->datesubmitted = time();
        $grade->update();
        $grade->set_hidden(time() + HOURSECS);

        $report = \gradereport_user\external\user::get_grade_items(
            (int)$this->course->id,
            (int)$this->student->id
        );
        $nativegrade = null;
        foreach ($report['usergrades'][0]['gradeitems'] as $gradeitemdata) {
            if ((int)$gradeitemdata['id'] === $this->catgradeitemid) {
                $nativegrade = $gradeitemdata;
                break;
            }
        }
        $this->assertNotNull($nativegrade);
        $this->assertTrue($nativegrade['gradehiddenbydate']);
        $this->assertNull($nativegrade['graderaw']);

        $response = get_my_gradebook::execute();
        $this->assertNull($this->find_grade($response, (int)$this->catassignment->id));
    }

    public function test_locked_but_visible_grade_is_returned(): void {
        $gradeitem = \grade_item::fetch(['id' => $this->catgradeitemid]);
        $gradeitem->update_final_grade((int)$this->student->id, 72.0, 'locked grade test');
        $grade = \grade_grade::fetch([
            'itemid' => $this->catgradeitemid,
            'userid' => $this->student->id,
        ]);
        $this->assertTrue($grade->set_locked(1));

        $response = get_my_gradebook::execute();
        $visiblegrade = $this->find_grade($response, (int)$this->catassignment->id);

        $this->assertNotNull($visiblegrade);
        $this->assertSame(72.0, $visiblegrade['score']);
    }

    public function test_grade_needing_recalculation_is_not_exposed(): void {
        $gradeitem = \grade_item::fetch(['id' => $this->catgradeitemid]);
        $gradeitem->update_final_grade((int)$this->student->id, 63.0, 'recalculation test');
        $gradeitem->needsupdate = 1;
        $gradeitem->update();

        $response = get_my_gradebook::execute();
        $grade = $this->find_grade($response, (int)$this->catassignment->id);

        $this->assertNotNull($grade);
        $this->assertSame('pending', $grade['status']);
        $this->assertArrayNotHasKey('score', $grade);
        $this->assertArrayNotHasKey('maximum', $grade);
        $this->assertArrayNotHasKey('percentage', $grade);
    }

    public function test_ungraded_items_do_not_return_fabricated_marks(): void {
        $response = get_my_gradebook::execute();
        $grade = $this->find_grade($response, (int)$this->assignment->id);

        $this->assertNotNull($grade);
        $this->assertSame('pending', $grade['status']);
        $this->assertArrayNotHasKey('score', $grade);
        $this->assertArrayNotHasKey('percentage', $grade);
    }

    public function test_assessment_metadata_classifies_categories_and_separates_final_exam(): void {
        $response = get_my_gradebook::execute();
        $cat = $this->find_grade($response, (int)$this->catassignment->id);
        $assignment = $this->find_grade($response, (int)$this->assignment->id);
        $quiz = $this->find_grade($response, (int)$this->regularquiz->id);
        $unitexam = $this->find_grade($response, (int)$this->unitquiz->id);
        $final = $this->find_grade($response, (int)$this->finalquiz->id);

        $this->assertNotNull($cat);
        $this->assertNotNull($assignment);
        $this->assertNotNull($quiz);
        $this->assertNotNull($unitexam);
        $this->assertNotNull($final);
        $this->assertSame('cat', $cat['assessmenttype']);
        $this->assertSame('assignment', $assignment['assessmenttype']);
        $this->assertSame('quiz', $quiz['assessmenttype']);
        $this->assertSame('exam', $unitexam['assessmenttype']);
        $this->assertSame((int)$this->unit->id, $unitexam['unitid']);
        $this->assertSame('final_exam', $final['assessmenttype']);
        $this->assertSame(0, $final['unitid']);
        $this->assertTrue($final['isfinalexam']);
    }

    public function test_student_does_not_receive_grades_for_an_unenrolled_course(): void {
        global $DB;

        $othercourse = $this->getDataGenerator()->create_course();
        course_create_sections_if_missing($othercourse->id, 1);
        $sections = $DB->get_records('course_sections', ['course' => $othercourse->id], 'section ASC');
        $topicsections = array_values(array_filter(
            $sections,
            static fn($section) => (int)$section->section > 0
        ));
        $otherassignment = $this->getDataGenerator()->create_module('assign', [
            'course' => $othercourse->id,
            'section' => (int)$topicsections[0]->section,
            'name' => 'Unenrolled course assessment',
            'grade' => 100,
        ]);
        $gradeitemid = (int)$DB->get_field('grade_items', 'id', [
            'courseid' => $othercourse->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $otherassignment->id,
            'itemnumber' => 0,
        ], MUST_EXIST);
        gradebook_helper::map_grade_item((int)$othercourse->id, (int)$topicsections[0]->id, $gradeitemid);
        \local_btiportal\assignment_meta_helper::save(
            (int)$otherassignment->id,
            (int)$othercourse->id,
            (int)$topicsections[0]->id,
            'ASSIGNMENT'
        );
        $gradeitem = \grade_item::fetch(['id' => $gradeitemid]);
        $gradeitem->update_final_grade((int)$this->student->id, 99.0, 'unrelated course');

        $response = get_my_gradebook::execute();

        $this->assertNull($this->find_grade($response, (int)$otherassignment->id));
    }

    public function test_existing_lecturer_unit_and_course_authorization_is_unchanged(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_user();
        $unrelatedteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $DB->insert_record('local_btiportal_unit', (object)[
            'courseid' => $this->course->id,
            'sectionid' => $this->unit->id,
            'teacheruserid' => $teacher->id,
            'timecreated' => time(),
        ]);

        $this->assertTrue(gradebook_helper::user_is_unit_teacher(
            (int)$teacher->id,
            (int)$this->course->id,
            (int)$this->unit->id
        ));
        $this->assertFalse(gradebook_helper::user_is_unit_teacher(
            (int)$unrelatedteacher->id,
            (int)$this->course->id,
            (int)$this->unit->id
        ));
        $this->assertTrue(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$teacher->id,
            (int)$this->course->id
        ));
        $this->assertFalse(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$unrelatedteacher->id,
            (int)$this->course->id
        ));
    }

    public function test_enrolled_lecturer_can_access_own_visible_grade(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $gradeitem = \grade_item::fetch(['id' => $this->catgradeitemid]);
        $gradeitem->update_final_grade((int)$teacher->id, 78.0, 'lecturer self grade test');
        $this->setUser($teacher);

        $response = get_my_gradebook::execute();
        $grade = $this->find_grade($response, (int)$this->catassignment->id);

        $this->assertNotNull($grade);
        $this->assertSame(78.0, $grade['score']);
    }

    public function test_quiz_without_student_visible_review_marks_does_not_return_a_mark(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/grade/report/user/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'section' => (int)$this->unit->section,
            'name' => 'Quiz with hidden review marks',
            'grade' => 100,
            'attempts' => 1,
            'marksduring' => 0,
            'marksimmediately' => 0,
            'maxmarksduring' => 0,
            'maxmarksimmediately' => 0,
            'marksopen' => 0,
            'maxmarksopen' => 0,
            'marksclosed' => 0,
            'maxmarksclosed' => 0,
            'overallfeedbackduring' => 0,
            'overallfeedbackimmediately' => 0,
            'overallfeedbackopen' => 0,
            'overallfeedbackclosed' => 1,
            'overallfeedbacks' => [[
                'mingrade' => 0,
                'maxgrade' => 101,
                'feedbacktext' => 'Overall feedback is visible.',
                'feedbacktextformat' => FORMAT_HTML,
            ]],
        ]);
        $cmid = (int)$DB->get_field('course_modules', 'id', [
            'course' => $this->course->id,
            'instance' => $quiz->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'quiz']),
        ], MUST_EXIST);
        $gradeitemid = (int)$DB->get_field('grade_items', 'id', [
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ], MUST_EXIST);
        gradebook_helper::map_grade_item((int)$this->course->id, (int)$this->unit->id, $gradeitemid);
        assessment_helper::save_meta(
            $cmid,
            (int)$this->course->id,
            (int)$this->unit->id,
            assessment_helper::MODULE_QUIZ,
            (int)$quiz->id,
            assessment_helper::TYPE_QUIZ,
            0.0,
            0,
            3,
            assessment_helper::ACTION_SUBMIT
        );

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($cmid)->id,
        ]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'defaultmark' => 1,
        ]);
        quiz_add_quiz_question($question->id, $quiz, 0, 1);
        $quizsettings = \mod_quiz\quiz_settings::create((int)$quiz->id);
        $quizsettings->get_grade_calculator()->recompute_quiz_sumgrades();

        $attemptid = $this->create_finished_quiz_attempt($quiz);
        $attempt = \mod_quiz\quiz_attempt::create($attemptid);
        $this->assertSame(\mod_quiz\quiz_attempt::FINISHED, $attempt->get_state());
        $quiz->timeclose = time() - 1;
        $DB->update_record('quiz', $quiz);
        grade_set_setting((int)$this->course->id, 'report_user_showhiddenitems', GRADE_REPORT_USER_SHOW_HIDDEN);
        grade_set_setting((int)$this->course->id, 'report_user_showrange', 1);
        $this->assertTrue(\grade_item::fetch(['id' => $gradeitemid])->is_hidden());
        $reviewoptions = quiz_get_review_options(
            $quiz,
            $attempt->get_attempt(),
            \context_module::instance($cmid)
        );
        $this->assertSame(\question_display_options::HIDDEN, $reviewoptions->marks);
        $this->assertSame(\question_display_options::VISIBLE, $reviewoptions->overallfeedback);

        $nativequizgrade = \mod_quiz_external::get_user_best_grade(
            (int)$quiz->id,
            (int)$this->student->id
        );
        $this->assertFalse($nativequizgrade['hasgrade']);
        $this->assertArrayNotHasKey('grade', $nativequizgrade);
        $this->assertSame('Overall feedback is visible.', $nativequizgrade['feedback']['feedbacktext']);
        $this->assertSame(FORMAT_HTML, $nativequizgrade['feedback']['feedbackformat']);

        $response = get_my_gradebook::execute();
        $grade = $this->find_grade($response, (int)$quiz->id);

        $this->assertNotNull($grade);
        $this->assertArrayNotHasKey('score', $grade);
        $this->assertArrayNotHasKey('maximum', $grade);
        $this->assertArrayNotHasKey('percentage', $grade);
        $this->assertSame('pending', $grade['status']);
        $this->assertSame('Overall feedback is visible.', $grade['feedback']);
        $this->assertSame(FORMAT_HTML, $grade['feedbackformat']);
    }

    public function test_quiz_with_visible_marks_returns_score_and_maximum(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'section' => (int)$this->unit->section,
            'name' => 'Quiz with visible review marks',
            'grade' => 100,
            'attempts' => 1,
            'marksduring' => 0,
            'marksimmediately' => 0,
            'maxmarksduring' => 0,
            'maxmarksimmediately' => 0,
            'marksopen' => 0,
            'maxmarksopen' => 0,
            'marksclosed' => 1,
            'maxmarksclosed' => 1,
        ]);
        $cmid = (int)$DB->get_field('course_modules', 'id', [
            'course' => $this->course->id,
            'instance' => $quiz->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'quiz']),
        ], MUST_EXIST);
        $gradeitemid = (int)$DB->get_field('grade_items', 'id', [
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ], MUST_EXIST);
        gradebook_helper::map_grade_item((int)$this->course->id, (int)$this->unit->id, $gradeitemid);
        assessment_helper::save_meta(
            $cmid,
            (int)$this->course->id,
            (int)$this->unit->id,
            assessment_helper::MODULE_QUIZ,
            (int)$quiz->id,
            assessment_helper::TYPE_QUIZ,
            0.0,
            0,
            3,
            assessment_helper::ACTION_SUBMIT
        );

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($cmid)->id,
        ]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'defaultmark' => 1,
        ]);
        quiz_add_quiz_question($question->id, $quiz, 0, 1);
        $quizsettings = \mod_quiz\quiz_settings::create((int)$quiz->id);
        $quizsettings->get_grade_calculator()->recompute_quiz_sumgrades();
        $attemptid = $this->create_finished_quiz_attempt($quiz);
        $attempt = \mod_quiz\quiz_attempt::create($attemptid);
        $this->assertSame(\mod_quiz\quiz_attempt::FINISHED, $attempt->get_state());
        $quiz->timeclose = time() - 1;
        $DB->update_record('quiz', $quiz);
        grade_set_setting((int)$this->course->id, 'report_user_showrange', 1);
        $reviewoptions = quiz_get_review_options(
            $quiz,
            $attempt->get_attempt(),
            \context_module::instance($cmid)
        );
        $this->assertSame(\question_display_options::MARK_AND_MAX, $reviewoptions->marks);

        $nativequizgrade = \mod_quiz_external::get_user_best_grade(
            (int)$quiz->id,
            (int)$this->student->id
        );
        $this->assertTrue($nativequizgrade['hasgrade']);
        $this->assertSame(100.0, (float)$nativequizgrade['grade']);

        $response = get_my_gradebook::execute();
        $grade = $this->find_grade($response, (int)$quiz->id);

        $this->assertNotNull($grade);
        $this->assertSame('graded', $grade['status']);
        $this->assertSame(100.0, $grade['score']);
        $this->assertSame(100.0, $grade['maximum']);
        $this->assertSame(100.0, $grade['percentage']);
    }

    /**
     * Create a finished, automatically graded attempt to exercise quiz review rules.
     */
    private function create_finished_quiz_attempt(stdClass $quiz): int {
        $quizsettings = \mod_quiz\quiz_settings::create((int)$quiz->id, (int)$this->student->id);
        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizsettings->get_context());
        $usage->set_preferred_behaviour($quizsettings->get_quiz()->preferredbehaviour);
        $time = time();
        $attempt = quiz_create_attempt($quizsettings, 1, null, $time, false, (int)$this->student->id);
        quiz_start_new_attempt($quizsettings, $usage, $attempt, 1, $time, [], []);
        quiz_attempt_save_started($quizsettings, $usage, $attempt);

        $attemptobj = \mod_quiz\quiz_attempt::create((int)$attempt->id);
        $questionattempt = $attemptobj->get_question_attempt(1);
        $correctresponse = $questionattempt->get_question()->get_correct_response();
        $response = [
            $questionattempt->get_field_prefix() . 'answer' => $correctresponse['answer'],
        ];
        $attemptobj->process_submitted_actions($time, false, $response);
        $attemptobj->process_submit($time, false);
        $attemptobj->process_grade_submission($time);

        return (int)$attempt->id;
    }
}
