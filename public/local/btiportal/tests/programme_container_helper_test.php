<?php
defined('MOODLE_INTERNAL') || die();

use local_btiportal\assessment_access_helper;
use local_btiportal\assessment_helper;
use local_btiportal\curriculum_structure_helper;
use local_btiportal\curriculum_unit_helper;
use local_btiportal\programme_container_helper;
use local_btiportal\student_admission_helper;
use local_btiportal\student_assessment_entitlement_helper;
use local_btiportal\student_course_access_helper;
use local_btiportal\student_enrolment_reconcile_helper;

/**
 * @covers \local_btiportal\programme_container_helper
 * @covers \local_btiportal\student_enrolment_reconcile_helper
 */
final class local_btiportal_programme_container_helper_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_shortname_collision_is_not_auto_mapped(): void {
        global $DB;
        if (!$DB->get_manager()->field_exists('local_btiportal_programme', 'containercourseid')) {
            $this->markTestSkipped('containercourseid is not installed.');
        }
        $now = time();
        $unitcourse = $this->getDataGenerator()->create_course(['shortname' => 'SHA-' . $now]);
        $owner = $this->insert_programme('OWN-' . $now, 0);
        $target = $this->insert_programme($unitcourse->shortname, 0);
        $DB->insert_record('local_btiportal_course_unit', (object)[
            'code' => 'U-' . $now,
            'name' => 'Unit that stole the shortname',
            'description' => '',
            'programmeid' => $owner,
            'curriculumid' => 0,
            'yearofstudy' => 1,
            'semestersequence' => 1,
            'courseid' => $unitcourse->id,
            'sectionid' => 0,
            'credithours' => 3,
            'unittype' => 'core',
            'status' => 'active',
            'isrequired' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => 0,
            'modifiedby' => 0,
        ]);

        $result = programme_container_helper::populate_unambiguous();
        $unmapped = array_values(array_filter(
            $result['unmapped'],
            static fn(array $row): bool => (int)$row['programmeid'] === $target
        ));
        $this->assertNotEmpty($unmapped);
        $this->assertSame('shortname_matches_a_unit_course', $unmapped[0]['reason']);
        $this->assertSame(0, programme_container_helper::get_container_courseid($target));
        $this->assertFalse(
            programme_container_helper::set_container_courseid($target, (int)$unitcourse->id)['ok']
        );
    }

    public function test_integer_container_join_is_collation_safe(): void {
        global $DB;
        if (!$DB->get_manager()->field_exists('local_btiportal_programme', 'containercourseid')) {
            $this->markTestSkipped('containercourseid is not installed.');
        }
        $course = $this->getDataGenerator()->create_course();
        $programmeid = $this->insert_programme('CIB-' . time(), (int)$course->id);
        $found = programme_container_helper::find_course_by_shortname($course->shortname);
        $this->assertNotNull($found);
        $rows = programme_container_helper::list_mapped_containers();
        $ids = array_map(static fn($row) => (int)$row->programmeid, $rows);
        $this->assertContains($programmeid, $ids);
    }

    public function test_semester1_student_is_not_enrolled_in_semester2_units(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_btiportal_curriculum')) {
            $this->markTestSkipped('Curriculum tables are not installed.');
        }
        $now = time();
        $container = $this->getDataGenerator()->create_course();
        $sem1course = $this->getDataGenerator()->create_course();
        $sem2course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $programmeid = $this->insert_programme('DIP-' . $now, (int)$container->id);
        $curriculumid = $DB->insert_record('local_btiportal_curriculum', (object)[
            'programmeid' => $programmeid,
            'code' => 'CUR-' . $now,
            'name' => 'Curriculum',
            'version' => 'v1',
            'description' => '',
            'status' => 'active',
            'effectivefrom' => 0,
            'effectiveto' => 0,
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $year = curriculum_structure_helper::create_year([
            'curriculumid' => $curriculumid,
            'yearnumber' => 1,
            'status' => 'active',
        ]);
        $this->assertTrue(!empty($year['ok']));
        $s1 = curriculum_structure_helper::create_semester([
            'curriculumyearid' => (int)$year['record']['id'],
            'semesternumber' => 1,
            'status' => 'active',
        ]);
        $s2 = curriculum_structure_helper::create_semester([
            'curriculumyearid' => (int)$year['record']['id'],
            'semesternumber' => 2,
            'status' => 'active',
        ]);
        $this->assertTrue(!empty($s1['ok']) && !empty($s2['ok']));
        $u1 = $this->insert_unit($programmeid, $curriculumid, $sem1course, 1, 1, 'S1-' . $now);
        $u2 = $this->insert_unit($programmeid, $curriculumid, $sem2course, 1, 2, 'S2-' . $now);
        curriculum_unit_helper::ensure_assignment_for_unit($u1);
        curriculum_unit_helper::ensure_assignment_for_unit($u2);
        $DB->insert_record('local_btiportal_student_profile', (object)[
            'userid' => $student->id,
            'schoolid' => 0,
            'departmentid' => 0,
            'programmeid' => $programmeid,
            'curriculumid' => $curriculumid,
            'curriculumyearid' => (int)$year['record']['id'],
            'curriculumsemesterid' => (int)$s1['record']['id'],
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

        student_course_access_helper::ensure_pathway_enrolments((int)$student->id);
        $this->assertTrue(is_enrolled(\context_course::instance((int)$container->id), (int)$student->id, '', true));
        $this->assertTrue(is_enrolled(\context_course::instance((int)$sem1course->id), (int)$student->id, '', true));
        $this->assertFalse(is_enrolled(\context_course::instance((int)$sem2course->id), (int)$student->id, '', true));
        $this->assertFalse(student_assessment_entitlement_helper::is_entitled((int)$student->id, (int)$sem2course->id));
    }

    public function test_site_admin_is_skipped(): void {
        global $DB;
        $adminid = (int)get_admin()->id;
        $course = $this->getDataGenerator()->create_course();
        $programmeid = $this->insert_programme('ADM-' . time(), (int)$course->id);
        $now = time();
        if (!$DB->record_exists('local_btiportal_student_profile', ['userid' => $adminid])) {
            $DB->insert_record('local_btiportal_student_profile', (object)[
                'userid' => $adminid,
                'schoolid' => 0,
                'departmentid' => 0,
                'programmeid' => $programmeid,
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
        }
        $result = student_enrolment_reconcile_helper::enrol_user($adminid);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('site_admin', $result['skipreason']);
        $this->assertSame(0, $result['enrolled']);
    }

    public function test_admission_enrols_container_and_final_can_open(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        $this->setUser($teacher);
        $programmeid = $this->insert_programme('FIN-' . time(), (int)$course->id);
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
                'name' => 'Q',
                'text' => 'Ok?',
                'correct' => true,
                'marks' => 1,
            ]]
        );
        $result = student_admission_helper::admit_student((int)$student->id, [
            'programmeid' => $programmeid,
            'curriculumid' => 0,
            'admissionstatus' => 'active',
            'academicstatus' => 'active',
            'status' => 'active',
        ], (int)$teacher->id);
        $this->assertTrue(!empty($result['ok']));
        $this->setUser($student);
        $visible = assessment_helper::list_for_courses([], (int)$student->id);
        $cmids = array_map(static fn(array $row): int => (int)$row['cmid'], $visible);
        $this->assertContains((int)$quiz['cmid'], $cmids);
        $opened = assessment_access_helper::assert_can_access_assessment(
            (int)$student->id,
            (int)$quiz['cmid'],
            true
        );
        $this->assertSame('ok', $opened['code']);
    }

    public function test_see_open_typed_denials_never_use_legacy_code(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course->id, 'student');
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        global $DB;
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
        $future = assessment_helper::create_quiz_assessment(
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
                'name' => 'Later',
                'text' => 'Later?',
                'correct' => true,
                'marks' => 1,
            ]]
        );
        $visible = assessment_helper::list_for_courses([(int)$course->id], (int)$student->id);
        $this->assertNotEmpty($visible);
        try {
            assessment_access_helper::assert_can_access_assessment((int)$student->id, (int)$future['cmid'], false);
            $this->fail('Expected typed denial');
        } catch (\moodle_exception $exception) {
            $this->assertSame('not_yet_open', $exception->errorcode);
            $this->assertNotSame('studentnotenrolled', $exception->errorcode);
        }
    }

    private function insert_programme(string $code, int $containercourseid): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('local_btiportal_programme', (object)[
            'code' => $code,
            'name' => $code,
            'description' => '',
            'departmentid' => 0,
            'moodlecategoryid' => 0,
            'containercourseid' => $containercourseid,
            'status' => 'active',
            'awardtype' => 'diploma',
            'duration' => 1,
            'durationunit' => 'years',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
    }

    private function insert_unit(
        int $programmeid,
        int $curriculumid,
        \stdClass $course,
        int $year,
        int $sem,
        string $code
    ): int {
        global $DB;
        $now = time();
        course_create_sections_if_missing($course->id, 1);
        $section = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$course->id]
        );
        return (int)$DB->insert_record('local_btiportal_course_unit', (object)[
            'code' => $code,
            'name' => $code,
            'description' => '',
            'programmeid' => $programmeid,
            'curriculumid' => $curriculumid,
            'yearofstudy' => $year,
            'semestersequence' => $sem,
            'courseid' => $course->id,
            'sectionid' => $section->id ?? 0,
            'credithours' => 3,
            'unittype' => 'core',
            'status' => 'active',
            'isrequired' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => 0,
            'modifiedby' => 0,
        ]);
    }
}
