<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/lib.php');

/**
 * Unit-scoped gradebook: mapping + read/write against Moodle grade items.
 */
class gradebook_helper {
    /**
     * True when userid is the assigned teacher for this exact course+unit (section).
     */
    public static function user_is_unit_teacher(int $userid, int $courseid, int $unitid): bool {
        global $DB;
        if ($userid <= 0 || $courseid <= 0 || $unitid <= 0) {
            return false;
        }
        if ($DB->record_exists('local_btiportal_unit', [
            'courseid' => $courseid,
            'sectionid' => $unitid,
            'teacheruserid' => $userid,
        ])) {
            return true;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_btiportal_course_unit_teacher} t
               JOIN {local_btiportal_course_unit} cu ON cu.id = t.courseunitid
              WHERE t.userid = :userid
                AND t.status = 'active'
                AND cu.courseid = :courseid
                AND cu.sectionid = :sectionid",
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'sectionid' => $unitid,
            ]
        );
    }

    /**
     * @throws \moodle_exception with errorcode notunitteacher
     */
    public static function require_unit_teacher(int $userid, int $courseid, int $unitid): void {
        if (!self::user_is_unit_teacher($userid, $courseid, $unitid)) {
            throw new \moodle_exception(
                'notunitteacher',
                'local_btiportal',
                '',
                (object)['courseid' => $courseid, 'unitid' => $unitid]
            );
        }
    }

    /**
     * Upsert a mapping row, stamping itemmodule/itemtype from mdl_grade_items.
     *
     * @return array{id:int, courseid:int, unitid:int, gradeitemid:int, itemmodule:string, itemtype:string}
     */
    public static function map_grade_item(int $courseid, int $unitid, int $gradeitemid): array {
        global $DB;

        if (!$DB->record_exists('course_sections', ['id' => $unitid, 'course' => $courseid])) {
            throw new \moodle_exception('invalidsectionid', 'error', '', $unitid);
        }

        $gi = $DB->get_record('grade_items', ['id' => $gradeitemid, 'courseid' => $courseid], '*', MUST_EXIST);
        $itemmodule = (string)($gi->itemmodule ?? '');
        $itemtype = (string)($gi->itemtype ?? '');

        $existing = $DB->get_record('local_btiportal_unit_gradeitem', [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'gradeitemid' => $gradeitemid,
        ], '*', IGNORE_MULTIPLE);

        $now = time();
        if ($existing) {
            $existing->itemmodule = $itemmodule;
            $existing->itemtype = $itemtype;
            $existing->timemodified = $now;
            $DB->update_record('local_btiportal_unit_gradeitem', $existing);
            $id = (int)$existing->id;
        } else {
            $id = (int)$DB->insert_record('local_btiportal_unit_gradeitem', (object)[
                'courseid' => $courseid,
                'unitid' => $unitid,
                'gradeitemid' => $gradeitemid,
                'itemmodule' => $itemmodule,
                'itemtype' => $itemtype,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        return [
            'id' => $id,
            'courseid' => $courseid,
            'unitid' => $unitid,
            'gradeitemid' => $gradeitemid,
            'itemmodule' => $itemmodule,
            'itemtype' => $itemtype,
        ];
    }

    /**
     * Map every grade item for activities whose course module sits in this section,
     * plus any existing manual items already mapped (re-stamp metadata).
     *
     * @return list<array{gradeitemid:int, itemmodule:string, itemtype:string, itemname:string}>
     */
    public static function sync_section_activity_grade_items(int $courseid, int $unitid): array {
        global $DB;

        $cms = $DB->get_records('course_modules', ['course' => $courseid, 'section' => $unitid, 'deletioninprogress' => 0]);
        $mapped = [];
        foreach ($cms as $cm) {
            $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
            if (!$modname) {
                continue;
            }
            // grade_items for this activity instance
            $items = $DB->get_records('grade_items', [
                'courseid' => $courseid,
                'itemtype' => 'mod',
                'itemmodule' => $modname,
                'iteminstance' => $cm->instance,
            ]);
            foreach ($items as $gi) {
                // Skip outcome/category-only rows without a real grade.
                if ((int)$gi->grademax <= 0 && $gi->gradetype == GRADE_TYPE_NONE) {
                    continue;
                }
                $row = self::map_grade_item($courseid, $unitid, (int)$gi->id);
                $mapped[(int)$gi->id] = [
                    'gradeitemid' => (int)$gi->id,
                    'itemmodule' => $row['itemmodule'],
                    'itemtype' => $row['itemtype'],
                    'itemname' => (string)($gi->itemname ?? $modname),
                ];
            }
        }

        return array_values($mapped);
    }

    /**
     * @return list<object> mapping rows joined with grade_items metadata
     */
    public static function get_mapped_grade_items(int $courseid, int $unitid): array {
        global $DB;

        $sql = "SELECT m.id AS mappingid, m.courseid, m.unitid, m.gradeitemid,
                       m.itemmodule, m.itemtype,
                       gi.itemname, gi.iteminstance, gi.grademax, gi.grademin, gi.gradetype, gi.scaleid
                  FROM {local_btiportal_unit_gradeitem} m
                  JOIN {grade_items} gi ON gi.id = m.gradeitemid
                 WHERE m.courseid = ? AND m.unitid = ?
              ORDER BY gi.sortorder ASC, gi.id ASC";

        return array_values($DB->get_records_sql($sql, [$courseid, $unitid]));
    }

    public static function mapping_owns_grade_item(int $courseid, int $unitid, int $gradeitemid): bool {
        global $DB;
        return $DB->record_exists('local_btiportal_unit_gradeitem', [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'gradeitemid' => $gradeitemid,
        ]);
    }

    /**
     * @return array{students: array, gradeitems: array, grades: array}
     */
    public static function get_unit_gradebook(int $courseid, int $unitid): array {
        global $DB;

        $course = get_course($courseid);
        $context = \context_course::instance($courseid);

        $users = get_enrolled_users($context, 'mod/assign:submit', 0, 'u.*', 'lastname, firstname');
        // Fall back to any enrolled user if submit cap filters too hard.
        if (empty($users)) {
            $users = get_enrolled_users($context, '', 0, 'u.*', 'lastname, firstname');
        }

        $students = [];
        foreach ($users as $u) {
            if (is_siteadmin($u) || has_capability('moodle/course:manageactivities', $context, $u)) {
                // Skip teachers/managers from the student roster.
                continue;
            }
            $students[] = [
                'id' => (int)$u->id,
                'fullname' => fullname($u),
                'email' => (string)$u->email,
                'firstname' => (string)$u->firstname,
                'lastname' => (string)$u->lastname,
            ];
        }

        $mapped = self::get_mapped_grade_items($courseid, $unitid);
        $gradeitems = [];
        $grades = [];

        $assigninstances = [];
        foreach ($mapped as $gi) {
            if ((string)$gi->itemmodule === 'assign' && (int)$gi->iteminstance > 0) {
                $assigninstances[] = (int)$gi->iteminstance;
            }
        }
        $metaByAssign = assignment_meta_helper::get_by_assignmentids($assigninstances);

        foreach ($mapped as $gi) {
            $itemmodule = (string)$gi->itemmodule;
            $itemtype = (string)$gi->itemtype;
            $readonly = ($itemmodule === 'quiz');
            $category = '';
            if ($itemmodule === 'assign' && (int)$gi->iteminstance > 0) {
                $category = $metaByAssign[(int)$gi->iteminstance]['category'] ?? '';
            }
            $gradeitems[] = [
                'id' => (int)$gi->gradeitemid,
                'name' => (string)($gi->itemname !== '' && $gi->itemname !== null
                    ? $gi->itemname
                    : ($itemmodule !== '' ? $itemmodule : $itemtype)),
                'itemmodule' => $itemmodule,
                'itemtype' => $itemtype,
                'iteminstance' => (int)$gi->iteminstance,
                'grademax' => (float)$gi->grademax,
                'readonly' => $readonly,
                'readonlyreason' => $readonly
                    ? 'Quiz grades are not editable in the portal gradebook — grade the quiz in Moodle.'
                    : '',
                'category' => $category,
            ];

            $graderecords = $DB->get_records('grade_grades', ['itemid' => $gi->gradeitemid]);
            foreach ($graderecords as $gg) {
                if ($gg->finalgrade === null && $gg->rawgrade === null) {
                    continue;
                }
                $grades[] = [
                    'studentid' => (int)$gg->userid,
                    'gradeitemid' => (int)$gi->gradeitemid,
                    'grade' => $gg->finalgrade !== null ? (float)$gg->finalgrade : (float)$gg->rawgrade,
                ];
            }
        }

        $groups = self::build_gradebook_groups($gradeitems);
        $categoryaverages = self::compute_category_averages($students, $gradeitems, $grades);
        $combinedaverages = self::compute_combined_averages($students, $categoryaverages);

        return [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'coursename' => format_string($course->fullname),
            'students' => $students,
            'gradeitems' => $gradeitems,
            'grades' => $grades,
            'groups' => $groups,
            'categoryaverages' => $categoryaverages,
            'combinedaverages' => $combinedaverages,
        ];
    }

    /**
     * Return the course-level Final Exam gradebook.
     *
     * Final Exams are native Moodle quizzes with unitid = 0 and are
     * intentionally not mapped to a Course Unit gradebook.
     *
     * @return array<string,mixed>
     */
    public static function get_final_exam_gradebook(int $courseid): array {
        global $DB;

        $course = get_course($courseid);
        $context = \context_course::instance($courseid);

        // Include all enrolled users, including learners who have not
        // attempted the Final Exam or received a grade yet.
        $users = get_enrolled_users(
            $context,
            '',
            0,
            'u.*',
            'lastname, firstname'
        );

        $students = [];
        foreach ($users as $u) {
            if (is_siteadmin($u)
                || has_capability('moodle/course:manageactivities', $context, $u)) {
                continue;
            }

            $students[] = [
                'id' => (int)$u->id,
                'fullname' => fullname($u),
                'email' => (string)$u->email,
                'firstname' => (string)$u->firstname,
                'lastname' => (string)$u->lastname,
            ];
        }

        // Match the quiz grade item to the plugin's explicit Final Exam
        // metadata. Do not include unrelated course-level quizzes.
        $items = $DB->get_records_sql(
            "SELECT gi.id AS gradeitemid,
                    gi.courseid,
                    gi.itemname,
                    gi.itemmodule,
                    gi.itemtype,
                    gi.iteminstance,
                    gi.grademax,
                    am.cmid
               FROM {local_btiportal_assessment_meta} am
               JOIN {course_modules} cm
                 ON cm.id = am.cmid
                AND cm.course = am.courseid
               JOIN {modules} m
                 ON m.id = cm.module
                AND m.name = 'quiz'
               JOIN {quiz} q
                 ON q.id = am.instanceid
                AND q.id = cm.instance
               JOIN {grade_items} gi
                 ON gi.courseid = am.courseid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule = 'quiz'
                AND gi.iteminstance = q.id
                AND gi.itemnumber = 0
              WHERE am.courseid = :courseid
                AND am.unitid = 0
                AND am.module = 'quiz'
                AND am.assessmenttype = 'final'
              ORDER BY gi.sortorder ASC, gi.id ASC",
            ['courseid' => $courseid]
        );

        $gradeitems = [];
        $grades = [];

        foreach ($items as $gi) {
            $gradeitemid = (int)$gi->gradeitemid;
            $iteminstance = (int)$gi->iteminstance;
            $itemname = trim((string)($gi->itemname ?? ''));

            $gradeitems[] = [
                'id' => $gradeitemid,
                'name' => $itemname !== '' ? $itemname : 'Final Exam',
                'itemmodule' => 'quiz',
                'itemtype' => (string)$gi->itemtype,
                'iteminstance' => $iteminstance,
                'grademax' => (float)$gi->grademax,
                'readonly' => true,
                'readonlyreason' =>
                    'Final Exam grades are managed in Moodle and cannot be edited in the portal.',
                'category' => '',
            ];

            $graderecords = $DB->get_records(
                'grade_grades',
                ['itemid' => $gradeitemid]
            );

            foreach ($graderecords as $gg) {
                if ($gg->finalgrade === null && $gg->rawgrade === null) {
                    continue;
                }

                $grades[] = [
                    'studentid' => (int)$gg->userid,
                    'gradeitemid' => $gradeitemid,
                    'grade' => $gg->finalgrade !== null
                        ? (float)$gg->finalgrade
                        : (float)$gg->rawgrade,
                ];
            }
        }

        $groups = self::build_gradebook_groups($gradeitems);

        // Quiz grades intentionally remain excluded from unit assessment
        // category averages and combined averages.
        $categoryaverages = self::compute_category_averages(
            $students,
            $gradeitems,
            $grades
        );
        $combinedaverages = self::compute_combined_averages(
            $students,
            $categoryaverages
        );

        return [
            'courseid' => $courseid,
            'unitid' => 0,
            'coursename' => format_string($course->fullname),
            'students' => $students,
            'gradeitems' => $gradeitems,
            'grades' => $grades,
            'groups' => $groups,
            'categoryaverages' => $categoryaverages,
            'combinedaverages' => $combinedaverages,
        ];
    }

    /**
     * Bucket key for a mapped grade item.
     * Quizzes always go to QUIZ (ignore meta). Assessed categories from assignment_meta.
     * Everything else → UNCATEGORIZED.
     */
    public static function group_key_for_item(array $gi): string {
        $module = strtolower(trim((string)($gi['itemmodule'] ?? '')));
        if ($module === 'quiz') {
            return 'QUIZ';
        }
        $cat = strtoupper(trim((string)($gi['category'] ?? '')));
        if (in_array($cat, assignment_meta_helper::allowed_categories(), true)) {
            return $cat;
        }
        return 'UNCATEGORIZED';
    }

    /**
     * Always returns CAT, ASSIGNMENT, EXAM, QUIZ, UNCATEGORIZED — empty groups stay present
     * so the UI can show "not yet configured".
     *
     * @param list<array> $gradeitems
     * @return list<array{key:string, label:string, type:string, configured:bool, itemcount:int, items:array}>
     */
    public static function build_gradebook_groups(array $gradeitems): array {
        $defs = [
            'CAT' => ['label' => 'CAT', 'type' => 'assessment'],
            'ASSIGNMENT' => ['label' => 'Assignment', 'type' => 'assessment'],
            'EXAM' => ['label' => 'Exam', 'type' => 'assessment'],
            'QUIZ' => ['label' => 'Quiz (view only)', 'type' => 'quiz'],
            'UNCATEGORIZED' => ['label' => 'Uncategorized', 'type' => 'uncategorized'],
        ];

        $buckets = [];
        foreach (array_keys($defs) as $key) {
            $buckets[$key] = [];
        }

        foreach ($gradeitems as $gi) {
            $key = self::group_key_for_item($gi);
            if (!isset($buckets[$key])) {
                $key = 'UNCATEGORIZED';
            }
            $buckets[$key][] = $gi;
        }

        $out = [];
        foreach ($defs as $key => $def) {
            $items = $buckets[$key];
            $out[] = [
                'key' => $key,
                'label' => $def['label'],
                'type' => $def['type'],
                'configured' => count($items) > 0,
                'itemcount' => count($items),
                'items' => array_values($items),
            ];
        }
        return $out;
    }

    /**
     * Per-student simple averages (and points totals) within CAT / EXAM / ASSIGNMENT only.
     * Uncategorized and Quiz are excluded. Dynamic item count (1…N).
     *
     * @param list<array> $students
     * @param list<array> $gradeitems
     * @param list<array> $grades
     * @return list<array{studentid:int, category:string, average:float, count:int, pointsearned:float, pointspossible:float}>
     */
    public static function compute_category_averages(array $students, array $gradeitems, array $grades): array {
        $itemsbycat = [];
        foreach ($gradeitems as $gi) {
            // Quizzes never enter category averages even if somehow tagged.
            if (strtolower((string)($gi['itemmodule'] ?? '')) === 'quiz') {
                continue;
            }
            $cat = strtoupper(trim((string)($gi['category'] ?? '')));
            if (!in_array($cat, assignment_meta_helper::allowed_categories(), true)) {
                continue;
            }
            $itemsbycat[$cat][(int)$gi['id']] = (float)$gi['grademax'];
        }

        $gradeLookup = [];
        foreach ($grades as $g) {
            $gradeLookup[(int)$g['studentid'] . ':' . (int)$g['gradeitemid']] = (float)$g['grade'];
        }

        $out = [];
        foreach ($students as $s) {
            $sid = (int)$s['id'];
            foreach ($itemsbycat as $cat => $itemmaxes) {
                $pcts = [];
                $earned = 0.0;
                $possible = 0.0;
                foreach ($itemmaxes as $itemid => $grademax) {
                    $key = $sid . ':' . $itemid;
                    if (!array_key_exists($key, $gradeLookup)) {
                        continue;
                    }
                    $score = $gradeLookup[$key];
                    $max = $grademax > 0 ? $grademax : 100.0;
                    $pcts[] = ($score / $max) * 100.0;
                    $earned += $score;
                    $possible += $max;
                }
                if (empty($pcts)) {
                    continue;
                }
                $out[] = [
                    'studentid' => $sid,
                    'category' => $cat,
                    'average' => round(array_sum($pcts) / count($pcts), 2),
                    'count' => count($pcts),
                    'pointsearned' => round($earned, 2),
                    'pointspossible' => round($possible, 2),
                ];
            }
        }
        return $out;
    }

    /**
     * Combined total = mean of category averages for categories with ≥1 graded item.
     * Uncategorized / Quiz never contribute.
     *
     * @param list<array> $students
     * @param list<array> $categoryaverages from compute_category_averages
     * @return list<array{studentid:int, average:float, categoriescounted:int}>
     */
    public static function compute_combined_averages(array $students, array $categoryaverages): array {
        $byStudent = [];
        foreach ($categoryaverages as $row) {
            $sid = (int)$row['studentid'];
            $cat = strtoupper((string)$row['category']);
            if (!in_array($cat, assignment_meta_helper::allowed_categories(), true)) {
                continue;
            }
            if ((int)$row['count'] < 1) {
                continue;
            }
            $byStudent[$sid][] = (float)$row['average'];
        }

        $out = [];
        foreach ($students as $s) {
            $sid = (int)$s['id'];
            if (empty($byStudent[$sid])) {
                continue;
            }
            $vals = $byStudent[$sid];
            $out[] = [
                'studentid' => $sid,
                'average' => round(array_sum($vals) / count($vals), 2),
                'categoriescounted' => count($vals),
            ];
        }
        return $out;
    }

    /**
     * Write a grade and re-read the confirmed Moodle value.
     *
     * @return array{ok:bool, editable:bool, writesource:string, grade:float|null, message:string}
     */
    public static function submit_unit_grade(
        int $courseid,
        int $unitid,
        int $studentid,
        int $gradeitemid,
        float $grade
    ): array {
        global $DB, $CFG;

        if (!self::mapping_owns_grade_item($courseid, $unitid, $gradeitemid)) {
            throw new \moodle_exception(
                'gradeitemnotmapped',
                'local_btiportal',
                '',
                (object)['gradeitemid' => $gradeitemid, 'unitid' => $unitid]
            );
        }

        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $studentid, '', true)) {
            throw new \moodle_exception('usernotincourse', 'error');
        }

        $gi = $DB->get_record('grade_items', ['id' => $gradeitemid, 'courseid' => $courseid], '*', MUST_EXIST);
        $itemmodule = (string)($gi->itemmodule ?? '');
        $itemtype = (string)($gi->itemtype ?? '');

        if ($itemmodule === 'quiz') {
            $current = self::read_final_grade($gradeitemid, $studentid);
            return [
                'ok' => false,
                'editable' => false,
                'writesource' => 'readonly_quiz',
                'grade' => $current !== null ? $current : -1.0,
                'message' => 'Quiz grades are not editable here. Open the quiz in Moodle to grade attempts.',
            ];
        }

        if ($itemmodule === 'assign' && $itemtype === 'mod') {
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            require_once($CFG->dirroot . '/mod/assign/externallib.php');
            $cm = get_coursemodule_from_instance('assign', $gi->iteminstance, $courseid, false, MUST_EXIST);
            $modcontext = \context_module::instance($cm->id);
            require_capability('mod/assign:grade', $modcontext);

            // Route through the same entry point as the mod_assign_save_grade webservice.
            \mod_assign_external::save_grade(
                (int)$gi->iteminstance,
                $studentid,
                $grade,
                -1,
                false,
                '',
                false
            );

            grade_regrade_final_grades($courseid);
            $confirmed = self::read_final_grade($gradeitemid, $studentid);

            return [
                'ok' => true,
                'editable' => true,
                'writesource' => 'mod_assign_save_grade',
                'grade' => $confirmed !== null ? $confirmed : -1.0,
                'message' => 'Grade saved via mod_assign_save_grade.',
            ];
        }

        // Manual (and other non-quiz, non-assign) grade items.
        // core_grades_update_grades requires a module CM; for manual items use the core
        // grade_item API that underpins Moodle gradebook writes.
        require_capability('moodle/grade:edit', $context);
        $gradeitem = \grade_item::fetch(['id' => $gradeitemid]);
        if (!$gradeitem) {
            throw new \moodle_exception('invalidgradeitemid', 'error');
        }

        $gradeitem->update_final_grade($studentid, $grade, 'local_btiportal');

        grade_regrade_final_grades($courseid);
        $confirmed = self::read_final_grade($gradeitemid, $studentid);

        return [
            'ok' => true,
            'editable' => true,
            'writesource' => 'core_grades_update_grades',
            'grade' => $confirmed !== null ? $confirmed : -1.0,
            'message' => 'Grade saved via core gradebook update.',
        ];
    }

    public static function read_final_grade(int $gradeitemid, int $userid): ?float {
        global $DB;
        $gg = $DB->get_record('grade_grades', ['itemid' => $gradeitemid, 'userid' => $userid]);
        if (!$gg) {
            return null;
        }
        if ($gg->finalgrade !== null) {
            return (float)$gg->finalgrade;
        }
        if ($gg->rawgrade !== null) {
            return (float)$gg->rawgrade;
        }
        return null;
    }
}
