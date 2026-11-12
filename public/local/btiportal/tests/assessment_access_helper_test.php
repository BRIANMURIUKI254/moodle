<?php
defined('MOODLE_INTERNAL') || die();

use local_btiportal\assessment_access_helper;
use local_btiportal\assessment_helper;

/**
 * @covers \local_btiportal\assessment_access_helper
 */
final class local_btiportal_assessment_access_helper_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    private function publish_open_quiz(\stdClass $course, \stdClass $teacher, \stdClass $section): array {
        $this->setUser($teacher);
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        global $DB;
        $DB->insert_record('local_btiportal_unit', (object)[
            'courseid' => $course->id,
            'sectionid' => $section->id,
            'teacheruserid' => $teacher->id,
            'timecreated' => time(),
        ]);
        return assessment_helper::create_quiz_assessment(
            (int)$course->id,
            (int)$section->id,
            'Unit CAT',
            '',
            assessment_helper::TYPE_CAT,
            50.0,
            25.0,
            time() - 3600,
            time() + 7200,
            0,
            1,
            0,
            0,
            0,
            3,
            'flag',
            [[
                'type' => 'truefalse',
                'name' => 'Access question',
                'text' => 'Is this a CAT?',
                'correct' => true,
                'marks' => 1,
            ]]
        );
    }

    public function test_unenrolled_student_gets_not_enrolled(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        $quiz = $this->publish_open_quiz($course, $teacher, $section);

        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], false);
            $this->fail('Unenrolled students must be denied.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('not_enrolled', $exception->errorcode);
        }
    }

    public function test_enrolled_student_can_access_open_quiz(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course->id, 'student');
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        $quiz = $this->publish_open_quiz($course, $teacher, $section);
        $result = assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], false);
        $this->assertSame('ok', $result['code']);
        $this->assertSame((int)$quiz['cmid'], $result['cmid']);
    }

    public function test_future_window_is_not_yet_open(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course->id, 'student');
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        $this->setUser($teacher);
        $DB->insert_record('local_btiportal_unit', (object)[
            'courseid' => $course->id,
            'sectionid' => $section->id,
            'teacheruserid' => $teacher->id,
            'timecreated' => time(),
        ]);
        $quiz = assessment_helper::create_quiz_assessment(
            (int)$course->id,
            (int)$section->id,
            'Future CAT',
            '',
            assessment_helper::TYPE_CAT,
            50.0,
            25.0,
            time() + 86400,
            time() + 172800,
            0,
            1,
            0,
            0,
            0,
            3,
            'flag',
            [[
                'type' => 'truefalse',
                'name' => 'Future question',
                'text' => 'Later?',
                'correct' => true,
                'marks' => 1,
            ]]
        );
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], false);
            $this->fail('Future assessments must be not_yet_open.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('not_yet_open', $exception->errorcode);
        }
    }

    public function test_programme_student_selfheal_enrols_for_final_exam(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['shortname' => 'IBF-TEST-' . time()]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        $this->setUser($teacher);

        if (!$DB->get_manager()->table_exists('local_btiportal_programme')
            || !$DB->get_manager()->table_exists('local_btiportal_student_profile')
        ) {
            $this->markTestSkipped('Academic tables are not installed.');
        }

        $now = time();
        $programmeid = $DB->insert_record('local_btiportal_programme', (object)[
            'code' => $course->shortname,
            'name' => 'Islamic Banking',
            'description' => '',
            'departmentid' => 0,
            'moodlecategoryid' => $course->category,
            'containercourseid' => $course->id,
            'status' => 'active',
            'awardtype' => 'diploma',
            'duration' => 1,
            'durationunit' => 'years',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->insert_record('local_btiportal_student_profile', (object)[
            'userid' => $student->id,
            'schoolid' => 0,
            'departmentid' => 0,
            'programmeid' => $programmeid,
            'curriculumid' => 1,
            'curriculumyearid' => 0,
            'curriculumsemesterid' => 0,
            'academicyearid' => 0,
            'semesterid' => 0,
            'admissiondate' => $now,
            'admissionstatus' => 'active',
            'academicstatus' => 'active',
            'expectedcompletion' => 0,
            'status' => 'active',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);

        $quiz = assessment_helper::create_quiz_assessment(
            (int)$course->id,
            0,
            'Programme Final',
            '',
            assessment_helper::TYPE_FINAL,
            50.0,
            25.0,
            time() - 3600,
            time() + 7200,
            0,
            1,
            0,
            0,
            0,
            3,
            'flag',
            [[
                'type' => 'truefalse',
                'name' => 'Final question',
                'text' => 'Final?',
                'correct' => true,
                'marks' => 1,
            ]]
        );

        $this->assertFalse(is_enrolled(\context_course::instance((int)$course->id), (int)$student->id, '', true));
        $result = assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], true);
        $this->assertSame('ok', $result['code']);
        $this->assertSame(1, $result['selfhealed']);
        $this->assertTrue(is_enrolled(\context_course::instance((int)$course->id), (int)$student->id, '', true));
    }

    public function test_closed_quiz_is_typed_closed(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course->id, 'student');
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        $this->setUser($teacher);
        $DB->insert_record('local_btiportal_unit', (object)[
            'courseid' => $course->id,
            'sectionid' => $section->id,
            'teacheruserid' => $teacher->id,
            'timecreated' => time(),
        ]);
        $quiz = assessment_helper::create_quiz_assessment(
            (int)$course->id,
            (int)$section->id,
            'Closed exam',
            '',
            assessment_helper::TYPE_FINAL,
            50.0,
            25.0,
            time() - 7200,
            time() - 60,
            0,
            1,
            0,
            0,
            0,
            3,
            'flag',
            [[
                'type' => 'truefalse',
                'name' => 'Closed question',
                'text' => 'Closed?',
                'correct' => true,
                'marks' => 1,
            ]]
        );
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], false);
            $this->fail('Closed quizzes must be closed.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('closed', $exception->errorcode);
            $this->assertNotSame('studentnotenrolled', $exception->errorcode);
        }
    }

    public function test_suspended_and_expired_enrolment_codes(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        $quiz = $this->publish_open_quiz($course, $teacher, $section);
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course->id, 'student');
        $ue = $DB->get_record_sql(
            "SELECT ue.id FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = ? AND e.courseid = ?",
            [$student->id, $course->id]
        );
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['id' => $ue->id]);
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], false);
            $this->fail('Suspended enrolment must be enrol_suspended.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('enrol_suspended', $exception->errorcode);
        }
        $DB->set_field('user_enrolments', 'status', ENROL_USER_ACTIVE, ['id' => $ue->id]);
        $DB->set_field('user_enrolments', 'timeend', time() - 10, ['id' => $ue->id]);
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], false);
            $this->fail('Expired enrolment must be enrol_expired.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('enrol_expired', $exception->errorcode);
        }
    }

    public function test_other_programme_student_cannot_open_final(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_btiportal_programme')) {
            $this->markTestSkipped('Academic tables are not installed.');
        }
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        $this->setUser($teacher);
        $now = time();
        $ownprogramme = $DB->insert_record('local_btiportal_programme', (object)[
            'code' => 'OWN-' . $now,
            'name' => 'Own',
            'description' => '',
            'departmentid' => 0,
            'moodlecategoryid' => $othercourse->category,
            'containercourseid' => $othercourse->id,
            'status' => 'active',
            'awardtype' => 'diploma',
            'duration' => 1,
            'durationunit' => 'years',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->insert_record('local_btiportal_programme', (object)[
            'code' => 'OTH-' . $now,
            'name' => 'Other',
            'description' => '',
            'departmentid' => 0,
            'moodlecategoryid' => $course->category,
            'containercourseid' => $course->id,
            'status' => 'active',
            'awardtype' => 'diploma',
            'duration' => 1,
            'durationunit' => 'years',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->insert_record('local_btiportal_student_profile', (object)[
            'userid' => $student->id,
            'schoolid' => 0,
            'departmentid' => 0,
            'programmeid' => $ownprogramme,
            'curriculumid' => 0,
            'curriculumyearid' => 0,
            'curriculumsemesterid' => 0,
            'academicyearid' => 0,
            'semesterid' => 0,
            'admissiondate' => $now,
            'admissionstatus' => 'active',
            'academicstatus' => 'active',
            'expectedcompletion' => 0,
            'status' => 'active',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $quiz = assessment_helper::create_quiz_assessment(
            (int)$course->id,
            0,
            'Other programme Final',
            '',
            assessment_helper::TYPE_FINAL,
            50.0,
            25.0,
            time() - 3600,
            time() + 7200,
            0,
            1,
            0,
            0,
            0,
            3,
            'flag',
            [[
                'type' => 'truefalse',
                'name' => 'Other',
                'text' => 'No?',
                'correct' => true,
                'marks' => 1,
            ]]
        );
        $visible = assessment_helper::list_for_courses([], (int)$student->id);
        $cmids = array_map(static fn(array $row): int => (int)$row['cmid'], $visible);
        $this->assertNotContains((int)$quiz['cmid'], $cmids);
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], true);
            $this->fail('Other-programme finals must be not_enrolled.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('not_enrolled', $exception->errorcode);
            $this->assertNotSame('studentnotenrolled', $exception->errorcode);
        }
    }

    public function test_assignment_open_and_closed(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course->id, 'student');
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        $this->setUser($teacher);
        $DB->insert_record('local_btiportal_unit', (object)[
            'courseid' => $course->id,
            'sectionid' => $section->id,
            'teacheruserid' => $teacher->id,
            'timecreated' => time(),
        ]);
        $open = \local_btiportal\assignment_helper::create_document_assignment(
            (int)$course->id,
            (int)$section->id,
            'Open assignment',
            '',
            100.0,
            0,
            \local_btiportal\assignment_meta_helper::CATEGORY_ASSIGNMENT,
            time() - 3600,
            time() + 7200,
            time() + 7200
        );
        $result = assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$open['cmid'], false);
        $this->assertSame('ok', $result['code']);
        $closed = \local_btiportal\assignment_helper::create_document_assignment(
            (int)$course->id,
            (int)$section->id,
            'Closed assignment',
            '',
            100.0,
            0,
            \local_btiportal\assignment_meta_helper::CATEGORY_ASSIGNMENT,
            time() - 7200,
            time() - 120,
            time() - 60
        );
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$closed['cmid'], false);
            $this->fail('Closed assignments must be closed.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('closed', $exception->errorcode);
        }
    }

    public function test_password_required_quiz(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course->id, 'student');
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        $quiz = $this->publish_open_quiz($course, $teacher, $section);
        $DB->set_field('quiz', 'password', 'secret', ['id' => (int)$quiz['quizid']]);
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$quiz['cmid'], false);
            $this->fail('Passworded quizzes must be password_required.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('password_required', $exception->errorcode);
        }
    }
}
