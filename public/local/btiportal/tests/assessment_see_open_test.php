<?php
defined('MOODLE_INTERNAL') || die();

use local_btiportal\assessment_access_helper;
use local_btiportal\assessment_helper;
use local_btiportal\assignment_helper;
use local_btiportal\assignment_meta_helper;
use local_btiportal\curriculum_structure_helper;
use local_btiportal\curriculum_unit_helper;
use local_btiportal\plugin_build_helper;
use local_btiportal\student_assessment_entitlement_helper;

/**
 * @covers \local_btiportal\student_assessment_entitlement_helper
 * @covers \local_btiportal\assessment_access_helper
 */
final class local_btiportal_assessment_see_open_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_plugin_build_marker_has_version(): void {
        $marker = plugin_build_helper::marker();
        $this->assertNotSame('', $marker['pluginversion']);
        $this->assertSame('2026111301', $marker['pluginversion']);
        $this->assertArrayHasKey('gitsha', $marker);
    }

    public function test_legacy_codes_are_aliased(): void {
        $this->assertSame('not_enrolled', assessment_access_helper::typed_code('studentnotenrolled'));
        $this->assertSame('restricted', assessment_access_helper::typed_code('quizaccessdenied'));
        try {
            assessment_access_helper::rethrow_typed(new \moodle_exception('studentnotenrolled', 'local_btiportal'));
            $this->fail('Expected typed exception');
        } catch (\moodle_exception $exception) {
            $this->assertSame('not_enrolled', $exception->errorcode);
            $this->assertStringContainsString('2026111301', (string)$exception->debuginfo);
        }
    }

    public function test_see_implies_open_for_quiz_exam_cat_assignment(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_btiportal_programme')
            || !$DB->get_manager()->table_exists('local_btiportal_curriculum')
            || !$DB->get_manager()->table_exists('local_btiportal_course_unit')
            || !$DB->get_manager()->table_exists('local_btiportal_student_profile')
        ) {
            $this->markTestSkipped('Academic tables are not installed.');
        }

        $fixture = $this->create_semester3_student_with_studied_unit();
        $studentid = (int)$fixture['student']->id;
        $this->setUser($fixture['teacher']);

        $cms = [
            'quiz' => $this->publish_quiz(
                $fixture['unitcourse'],
                $fixture['teacher'],
                $fixture['unitsection'],
                assessment_helper::TYPE_QUIZ,
                'Unit Quiz'
            ),
            'cat' => $this->publish_quiz(
                $fixture['unitcourse'],
                $fixture['teacher'],
                $fixture['unitsection'],
                assessment_helper::TYPE_CAT,
                'Unit CAT'
            ),
            'exam' => $this->publish_quiz(
                $fixture['programmecourse'],
                $fixture['teacher'],
                null,
                assessment_helper::TYPE_FINAL,
                'Programme Final'
            ),
            'assignment' => $this->publish_assignment(
                $fixture['unitcourse'],
                $fixture['teacher'],
                $fixture['unitsection']
            ),
        ];

        $this->setUser($fixture['student']);
        $this->assertFalse(is_enrolled(
            \context_course::instance((int)$fixture['unitcourse']->id),
            $studentid,
            '',
            true
        ));
        $this->assertFalse(is_enrolled(
            \context_course::instance((int)$fixture['programmecourse']->id),
            $studentid,
            '',
            true
        ));

        $kind = student_assessment_entitlement_helper::classify_course(
            $studentid,
            (int)$fixture['unitcourse']->id
        );
        $this->assertSame(student_assessment_entitlement_helper::KIND_STUDIED_UNIT, $kind['kind']);
        $container = student_assessment_entitlement_helper::classify_course(
            $studentid,
            (int)$fixture['programmecourse']->id
        );
        $this->assertSame(student_assessment_entitlement_helper::KIND_PROGRAMME_CONTAINER, $container['kind']);

        $visible = assessment_helper::list_for_courses([], $studentid);
        $visiblecmids = array_map(static fn(array $row): int => (int)$row['cmid'], $visible);
        foreach ($cms as $type => $created) {
            $this->assertContains(
                (int)$created['cmid'],
                $visiblecmids,
                "Student must SEE the {$type} assessment before enrolment"
            );
            $opened = assessment_access_helper::assert_can_access_assessment(
                $studentid,
                (int)$created['cmid'],
                true
            );
            $this->assertSame('ok', $opened['code'], "Student must OPEN the {$type} assessment");
            $this->assertSame(1, $opened['selfhealed']);
            $this->assertSame('2026111301', $opened['pluginversion']);
        }

        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);
        $hidden = assessment_helper::list_for_courses([], (int)$stranger->id);
        $hiddencmids = array_map(static fn(array $row): int => (int)$row['cmid'], $hidden);
        foreach ($cms as $created) {
            $this->assertNotContains((int)$created['cmid'], $hiddencmids);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function create_semester3_student_with_studied_unit(): array {
        global $DB;
        $now = time();
        $stamp = (string)$now;
        $programmecourse = $this->getDataGenerator()->create_course(['shortname' => 'IBF-' . $stamp]);
        $unitcourse = $this->getDataGenerator()->create_course(['shortname' => 'UNIT26-' . $stamp]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$programmecourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$unitcourse->id, 'editingteacher');

        course_create_sections_if_missing($unitcourse->id, 1);
        $unitsection = $DB->get_record_sql(
            'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
            [$unitcourse->id]
        );

        $programmeid = $DB->insert_record('local_btiportal_programme', (object)[
            'code' => $programmecourse->shortname,
            'name' => 'Islamic Banking',
            'description' => '',
            'departmentid' => 0,
            'moodlecategoryid' => $programmecourse->category,
            'containercourseid' => $programmecourse->id,
            'status' => 'active',
            'awardtype' => 'diploma',
            'duration' => 2,
            'durationunit' => 'years',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $curriculumid = $DB->insert_record('local_btiportal_curriculum', (object)[
            'programmeid' => $programmeid,
            'code' => 'IBF-CUR-' . $stamp,
            'name' => 'IBF curriculum',
            'version' => 'v' . $stamp,
            'description' => '',
            'status' => 'active',
            'effectivefrom' => 0,
            'effectiveto' => 0,
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $year1 = curriculum_structure_helper::create_year([
            'curriculumid' => $curriculumid,
            'yearnumber' => 1,
            'name' => 'Year 1',
            'status' => 'active',
        ]);
        $year2 = curriculum_structure_helper::create_year([
            'curriculumid' => $curriculumid,
            'yearnumber' => 2,
            'name' => 'Year 2',
            'status' => 'active',
        ]);
        $this->assertTrue(!empty($year1['ok']) && !empty($year2['ok']), json_encode([$year1, $year2]));
        $sem1 = curriculum_structure_helper::create_semester([
            'curriculumyearid' => (int)$year1['record']['id'],
            'semesternumber' => 1,
            'status' => 'active',
        ]);
        $sem3 = curriculum_structure_helper::create_semester([
            'curriculumyearid' => (int)$year2['record']['id'],
            'semesternumber' => 1,
            'status' => 'active',
        ]);
        $this->assertTrue(!empty($sem1['ok']) && !empty($sem3['ok']), json_encode([$sem1, $sem3]));

        $unitid = $DB->insert_record('local_btiportal_course_unit', (object)[
            'code' => 'IBF26-' . $stamp,
            'name' => 'Studied unit on course 26',
            'description' => '',
            'programmeid' => $programmeid,
            'curriculumid' => $curriculumid,
            'yearofstudy' => 1,
            'semestersequence' => 1,
            'courseid' => $unitcourse->id,
            'sectionid' => $unitsection->id,
            'credithours' => 3,
            'unittype' => 'core',
            'status' => 'active',
            'isrequired' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => 0,
            'modifiedby' => 0,
        ]);
        curriculum_unit_helper::ensure_assignment_for_unit((int)$unitid);

        $DB->insert_record('local_btiportal_student_profile', (object)[
            'userid' => $student->id,
            'schoolid' => 0,
            'departmentid' => 0,
            'programmeid' => $programmeid,
            'curriculumid' => $curriculumid,
            'curriculumyearid' => (int)$year2['record']['id'],
            'curriculumsemesterid' => (int)$sem3['record']['id'],
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

        return [
            'student' => $student,
            'teacher' => $teacher,
            'programmecourse' => $programmecourse,
            'unitcourse' => $unitcourse,
            'unitsection' => $unitsection,
        ];
    }

    private function publish_quiz(
        \stdClass $course,
        \stdClass $teacher,
        ?\stdClass $section,
        string $type,
        string $name
    ): array {
        global $DB;
        $this->setUser($teacher);
        if ($section) {
            if (!$DB->record_exists('local_btiportal_unit', [
                'courseid' => $course->id,
                'sectionid' => $section->id,
            ])) {
                $DB->insert_record('local_btiportal_unit', (object)[
                    'courseid' => $course->id,
                    'sectionid' => $section->id,
                    'teacheruserid' => $teacher->id,
                    'timecreated' => time(),
                ]);
            }
        }
        return assessment_helper::create_quiz_assessment(
            (int)$course->id,
            $section ? (int)$section->id : 0,
            $name,
            '',
            $type,
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
                'name' => $name . ' question',
                'text' => 'Ok?',
                'correct' => true,
                'marks' => 1,
            ]]
        );
    }

    private function publish_assignment(\stdClass $course, \stdClass $teacher, \stdClass $section): array {
        global $DB;
        $this->setUser($teacher);
        if (!$DB->record_exists('local_btiportal_unit', [
            'courseid' => $course->id,
            'sectionid' => $section->id,
        ])) {
            $DB->insert_record('local_btiportal_unit', (object)[
                'courseid' => $course->id,
                'sectionid' => $section->id,
                'teacheruserid' => $teacher->id,
                'timecreated' => time(),
            ]);
        }
        return assignment_helper::create_document_assignment(
            (int)$course->id,
            (int)$section->id,
            'Unit Assignment',
            '',
            100.0,
            0,
            assignment_meta_helper::CATEGORY_ASSIGNMENT,
            time() - 3600,
            time() + 7200,
            time() + 7200
        );
    }
}
