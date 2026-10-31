<?php
defined('MOODLE_INTERNAL') || die();

use local_btiportal\assessment_helper;
use local_btiportal\exam_monitor_helper;
use local_btiportal\external\create_quiz_assessment;
use local_btiportal\gradebook_helper;
use local_btiportal\lecturer_teaching_helper;

/**
 * Final Exam and unit quiz publication regressions.
 *
 * @package local_btiportal
 * @covers \local_btiportal\assessment_helper
 * @covers \local_btiportal\lecturer_teaching_helper
 */
final class local_btiportal_assessment_helper_test extends advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $otherteacher;
    /** @var \stdClass */
    private $student;
    /** @var int */
    private $timeopen;
    /** @var int */
    private $timeclose;
    /** @var \stdClass */
    private $unit;
    /** @var \stdClass */
    private $unassignedunit;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        global $DB;
        $this->course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->otherteacher = $this->getDataGenerator()->create_user();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->otherteacher->id, $othercourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->otherteacher->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->timeopen = time() + 3600;
        $this->timeclose = time() + 7200;

        course_create_sections_if_missing($this->course->id, 2);
        $sections = $DB->get_records('course_sections', ['course' => $this->course->id], 'section ASC');
        $units = array_values(array_filter(
            $sections,
            static fn($section) => (int)$section->section > 0
        ));
        $this->unit = $units[0];
        $this->unassignedunit = $units[1];
    }

    /** @return list<array<string,mixed>> */
    private function true_false_question(): array {
        return [[
            'type' => 'truefalse',
            'name' => 'Assessment regression question',
            'text' => 'Moodle creates quiz questions through its question APIs.',
            'correct' => true,
            'marks' => 1,
        ]];
    }

    /** @param list<array<string,mixed>> $questions */
    private function create_quiz(int $unitid, string $type, array $questions, string $name): array {
        return assessment_helper::create_quiz_assessment(
            (int)$this->course->id,
            $unitid,
            $name,
            '',
            $type,
            50.0,
            25.0,
            $this->timeopen,
            $this->timeclose,
            3600,
            1,
            0,
            0,
            0,
            3,
            'submit_flag',
            $questions
        );
    }

    /** @param list<array<string,mixed>> $questions */
    private function publish_through_endpoint(int $unitid, string $type, array $questions, string $name): array {
        return create_quiz_assessment::execute(
            (int)$this->course->id,
            $unitid,
            $name,
            '',
            $type,
            50.0,
            25.0,
            $this->timeopen,
            $this->timeclose,
            3600,
            1,
            0,
            0,
            0,
            3,
            'submit_flag',
            (string)json_encode($questions)
        );
    }

    public function test_assigned_course_teacher_can_publish_final_exam_without_unit_assignment_and_retry_is_idempotent(): void {
        global $DB;
        $this->setUser($this->teacher);

        $this->assertTrue(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$this->teacher->id,
            (int)$this->course->id
        ));
        $this->assertFalse(gradebook_helper::user_is_unit_teacher(
            (int)$this->teacher->id,
            (int)$this->course->id,
            0
        ));
        $this->assertSame('active', $this->course_status_for_teacher());

        $first = $this->publish_through_endpoint(
            0,
            'exam',
            $this->true_false_question(),
            'Final Exam retry test'
        );
        $second = $this->publish_through_endpoint(
            0,
            assessment_helper::TYPE_FINAL,
            $this->true_false_question(),
            'Final Exam retry test'
        );

        $this->assertSame(0, $first['unitid']);
        $this->assertFalse($first['mapped']);
        $this->assertSame(0, $DB->count_records('local_btiportal_unit_gradeitem', [
            'gradeitemid' => $first['gradeitemid'],
        ]));
        $this->assertSame($first['quizid'], $second['quizid']);
        $this->assertSame($first['cmid'], $second['cmid']);
        $generalsection = $DB->get_record('course_sections', [
            'course' => $this->course->id,
            'section' => 0,
        ], '*', MUST_EXIST);
        $coursemodule = $DB->get_record('course_modules', ['id' => $first['cmid']], '*', MUST_EXIST);
        $this->assertSame((int)$generalsection->id, (int)$coursemodule->section);
        $this->assertSame(0, (int)$generalsection->section);
        $this->assertSame(0, assessment_helper::get_one(
            (int)$first['cmid'],
            (int)$this->teacher->id
        )['unitid']);
        $this->assertCount(1, assessment_helper::get_quiz_questions_for_teacher(
            (int)$first['cmid'],
            (int)$this->teacher->id
        ));
        $this->assertSame([], exam_monitor_helper::list_events(
            (int)$first['cmid'],
            (int)$this->teacher->id
        ));
        $listed = assessment_helper::list_for_courses(
            [(int)$this->course->id],
            (int)$this->teacher->id
        );
        $listedfinal = array_values(array_filter(
            $listed,
            static fn($assessment) => (int)$assessment['cmid'] === (int)$first['cmid']
        ));
        $this->assertCount(1, $listedfinal);
        $this->assertSame(0, $listedfinal[0]['unitid']);
        $this->assertSame(1, $DB->count_records('quiz', ['course' => $this->course->id]));
        $this->assertSame(1, $DB->count_records('local_btiportal_assessment_meta', [
            'cmid' => $first['cmid'],
            'assessmenttype' => assessment_helper::TYPE_FINAL,
            'unitid' => 0,
        ]));
    }

    public function test_teacher_assigned_only_to_another_course_cannot_publish_final_exam(): void {
        $this->setUser($this->otherteacher);
        try {
            $this->publish_through_endpoint(
                0,
                assessment_helper::TYPE_FINAL,
                $this->true_false_question(),
                'Unauthorized Final Exam'
            );
            $this->fail('A teacher not assigned to the course must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('notcourseteacher', $exception->errorcode);
        }
    }

    public function test_teaching_role_is_preserved_when_user_has_another_course_role(): void {
        global $DB;
        $this->setUser($this->teacher);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        role_assign(
            (int)$studentrole->id,
            (int)$this->teacher->id,
            \context_course::instance((int)$this->course->id)->id
        );

        $this->assertTrue(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$this->teacher->id,
            (int)$this->course->id
        ));
        $courses = lecturer_teaching_helper::list_moodle_teaching_courses((int)$this->teacher->id);
        $listedcourse = array_values(array_filter(
            $courses,
            fn($course) => (int)$course['courseid'] === (int)$this->course->id
        ));
        $this->assertCount(1, $listedcourse);
        $this->assertSame('editingteacher', $listedcourse[0]['role']);
    }

    public function test_inactive_or_expired_course_enrolments_are_rejected(): void {
        global $DB;
        $this->setUser($this->teacher);
        $instance = $DB->get_record('enrol', [
            'courseid' => $this->course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        $enrolment = $DB->get_record('user_enrolments', [
            'enrolid' => $instance->id,
            'userid' => $this->teacher->id,
        ], '*', MUST_EXIST);

        $this->assertTrue(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$this->teacher->id,
            (int)$this->course->id
        ));

        $instance->status = ENROL_INSTANCE_DISABLED;
        $DB->update_record('enrol', $instance);
        $this->assertFalse(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$this->teacher->id,
            (int)$this->course->id
        ));
        $this->assertSame('inactive', $this->course_status_for_teacher());
        $instance->status = ENROL_INSTANCE_ENABLED;
        $DB->update_record('enrol', $instance);

        $enrolment->timeend = time() - 1;
        $DB->update_record('user_enrolments', $enrolment);
        $this->assertFalse(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$this->teacher->id,
            (int)$this->course->id
        ));
        $this->assertSame('inactive', $this->course_status_for_teacher());

        $enrolment->timeend = 0;
        $enrolment->timestart = time() + 3600;
        $DB->update_record('user_enrolments', $enrolment);
        $this->assertFalse(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$this->teacher->id,
            (int)$this->course->id
        ));
        $this->assertSame('inactive', $this->course_status_for_teacher());

        $enrolment->timestart = 0;
        $enrolment->status = ENROL_USER_SUSPENDED;
        $DB->update_record('user_enrolments', $enrolment);
        $this->assertFalse(lecturer_teaching_helper::user_teaches_moodle_course(
            (int)$this->teacher->id,
            (int)$this->course->id
        ));
        $this->assertSame('inactive', $this->course_status_for_teacher());
    }

    private function course_status_for_teacher(): string {
        foreach (lecturer_teaching_helper::list_moodle_teaching_courses((int)$this->teacher->id) as $course) {
            if ((int)$course['courseid'] === (int)$this->course->id) {
                return (string)$course['statuslabel'];
            }
        }
        $this->fail('The teacher role should remain present in the course listing.');
        return '';
    }

    /**
     * Create a user with only the supplied capabilities in this course context.
     *
     * @param list<string> $capabilities
     */
    private function create_course_capability_user(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance((int)$this->course->id);
        role_assign($roleid, (int)$user->id, $context->id);
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id);
        }
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    public function test_user_with_required_quiz_creation_capabilities_is_allowed(): void {
        $user = $this->create_course_capability_user([
            'moodle/course:manageactivities',
            'moodle/question:add',
        ]);
        $this->setUser($user);
        assessment_helper::require_quiz_creation_capabilities(
            \context_course::instance((int)$this->course->id)
        );
        $this->assertTrue(has_capability(
            'moodle/course:manageactivities',
            \context_course::instance((int)$this->course->id)
        ));
        $this->assertTrue(has_capability(
            'moodle/question:add',
            \context_course::instance((int)$this->course->id)
        ));
    }

    public function test_quiz_addinstance_without_manageactivities_is_not_sufficient(): void {
        $user = $this->create_course_capability_user([
            'mod/quiz:addinstance',
            'moodle/question:add',
        ]);
        $this->setUser($user);
        $context = \context_course::instance((int)$this->course->id);
        $this->assertTrue(has_capability('mod/quiz:addinstance', $context));
        $this->assertFalse(has_capability('moodle/course:manageactivities', $context));

        try {
            assessment_helper::require_quiz_creation_capabilities($context);
            $this->fail('Quiz add-instance capability alone must not pass Moodle module-creation preflight.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('missingquizcapability', $exception->errorcode);
        }
    }

    public function test_user_without_quiz_or_activity_creation_capability_is_rejected(): void {
        $user = $this->create_course_capability_user(['moodle/question:add']);
        $this->setUser($user);
        $context = \context_course::instance((int)$this->course->id);
        $this->assertFalse(has_capability('moodle/course:manageactivities', $context));
        $this->assertFalse(has_capability('mod/quiz:addinstance', $context));

        try {
            assessment_helper::require_quiz_creation_capabilities($context);
            $this->fail('A user without either quiz-creation capability must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('missingquizcapability', $exception->errorcode);
        }
    }

    public function test_quiz_capability_does_not_replace_course_teaching_assignment(): void {
        $user = $this->create_course_capability_user([
            'moodle/course:manageactivities',
            'moodle/question:add',
        ]);
        $this->getDataGenerator()->enrol_user(
            (int)$user->id,
            (int)$this->course->id,
            'student'
        );
        $this->setUser($user);

        try {
            $this->publish_through_endpoint(
                0,
                assessment_helper::TYPE_FINAL,
                $this->true_false_question(),
                'Teacher assignment is still required'
            );
            $this->fail('Quiz-creation capabilities must not replace the course teaching assignment.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('notcourseteacher', $exception->errorcode);
        }
    }

    public function test_assigned_unit_teacher_can_publish_ordinary_quiz_but_unassigned_unit_is_rejected(): void {
        global $DB;
        $this->setUser($this->teacher);
        $DB->insert_record('local_btiportal_unit', (object)[
            'courseid' => $this->course->id,
            'sectionid' => $this->unit->id,
            'teacheruserid' => $this->teacher->id,
            'timecreated' => time(),
        ]);
        gradebook_helper::require_unit_teacher(
            (int)$this->teacher->id,
            (int)$this->course->id,
            (int)$this->unit->id
        );

        $result = $this->publish_through_endpoint(
            (int)$this->unit->id,
            assessment_helper::TYPE_QUIZ,
            $this->true_false_question(),
            'Assigned unit quiz'
        );
        $this->assertTrue($result['mapped']);
        $this->assertSame((int)$this->unit->id, $result['unitid']);

        try {
            gradebook_helper::require_unit_teacher(
                (int)$this->teacher->id,
                (int)$this->course->id,
                (int)$this->unassignedunit->id
            );
            $this->fail('An unassigned Course Unit must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('notunitteacher', $exception->errorcode);
        }
    }

    public function test_failed_question_creation_rolls_back_quiz_and_metadata(): void {
        global $DB;
        $this->setUser($this->teacher);
        $quizcount = $DB->count_records('quiz', ['course' => $this->course->id]);
        $metacount = $DB->count_records('local_btiportal_assessment_meta', ['courseid' => $this->course->id]);

        try {
            $this->create_quiz(0, assessment_helper::TYPE_FINAL, [[
                'type' => 'not-a-question-type',
                'text' => 'Invalid question type',
            ]], 'Failed Final Exam');
            $this->fail('Invalid question data must fail quiz publication.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('Unsupported question type', $exception->getMessage());
        }

        $this->assertSame($quizcount, $DB->count_records('quiz', ['course' => $this->course->id]));
        $this->assertSame($metacount, $DB->count_records('local_btiportal_assessment_meta', ['courseid' => $this->course->id]));
    }

    public function test_missing_and_invalid_course_ids_are_rejected(): void {
        $parameters = create_quiz_assessment::execute_parameters();
        $validparams = [
            'unitid' => 0,
            'name' => 'Course id validation',
            'assessmenttype' => assessment_helper::TYPE_FINAL,
            'questionsjson' => json_encode($this->true_false_question()),
        ];
        foreach ([
            $validparams,
            array_merge($validparams, ['courseid' => null]),
        ] as $invalidparams) {
            try {
                \core_external\external_api::validate_parameters($parameters, $invalidparams);
                $this->fail('Missing or null course ids must be rejected.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        $this->setUser($this->teacher);
        foreach ([0, SITEID, 99999999] as $courseid) {
            try {
                create_quiz_assessment::execute(
                    $courseid,
                    0,
                    'Invalid course test',
                    '',
                    assessment_helper::TYPE_FINAL,
                    50.0,
                    25.0,
                    time() + 3600,
                    time() + 7200,
                    3600,
                    1,
                    0,
                    0,
                    0,
                    3,
                    'submit_flag',
                    json_encode($this->true_false_question())
                );
                $this->fail('Invalid Moodle course ids must be rejected.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('invalidcourseid', $exception->errorcode);
            }
        }
    }
}