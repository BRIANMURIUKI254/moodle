<?php
/**
 * READ-ONLY audit of gradebook / Part A verification test artifacts.
 * Does not delete anything.
 *
 * Run:
 *   docker exec moodle-app php /var/www/html/public/local/btiportal/cli/audit_test_grade_artifacts.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB, $CFG;

function out(string $s = ''): void {
    echo $s . PHP_EOL;
}

out('=== AUDIT: test gradebook / document-assignment artifacts (READ-ONLY) ===');
out('Generated: ' . date('c'));
out('');

$course = $DB->get_record('course', ['shortname' => 'TEST01'], '*', IGNORE_MISSING);
if (!$course) {
    $course = $DB->get_record('course', ['id' => 4], '*', IGNORE_MISSING);
}
if (!$course) {
    out('FAIL: course TEST01 / id=4 not found');
    exit(1);
}
$courseid = (int)$course->id;
out("Course: id={$courseid} shortname={$course->shortname} fullname={$course->fullname}");
out('');

// --- Users of interest ---
out('--- Users (portal / verification accounts) ---');
$usernames = ['portal_instructor', 'portal_instructor2', 'portal_student'];
$users = [];
foreach ($usernames as $un) {
    $u = $DB->get_record('user', ['username' => $un, 'deleted' => 0], '*', IGNORE_MISSING);
    if ($u) {
        $users[$un] = $u;
        $enrol = $DB->record_exists_sql(
            "SELECT 1 FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = ? AND e.courseid = ?",
            [$u->id, $courseid]
        );
        out(sprintf(
            "  CONFIDENT test fixture user: username=%s id=%d name=%s %s email=%s enrolled_in_course=%s",
            $u->username,
            $u->id,
            $u->firstname,
            $u->lastname,
            $u->email,
            $enrol ? 'yes' : 'no'
        ));
    } else {
        out("  MISSING: {$un}");
    }
}
out('');
out('  Note: these usernames are created/ensured by verify_gradebook_part_d.php and used by Part A.');
out('  They are portal test accounts (emails *@example.com), NOT evidence of real staff.');
out('  Recommendation: do NOT delete accounts without explicit OK — may still be used for ongoing portal login tests.');
out('');

// Any other enrolled students who look like people
out('--- Other enrolled users on this course (possible real vs dummy) ---');
$enrolled = $DB->get_records_sql(
    "SELECT u.id, u.username, u.firstname, u.lastname, u.email
       FROM {user} u
       JOIN {user_enrolments} ue ON ue.userid = u.id
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE e.courseid = ? AND u.deleted = 0
   ORDER BY u.lastname, u.firstname",
    [$courseid]
);
foreach ($enrolled as $u) {
    if (isset($users[$u->username])) {
        continue;
    }
    $flag = (str_ends_with((string)$u->email, '@example.com') || str_starts_with($u->username, 'portal_'))
        ? 'LIKELY_TEST'
        : 'AMBIGUOUS (treat as real — do not delete account)';
    out(sprintf(
        "  [%s] id=%d username=%s name=%s %s email=%s",
        $flag,
        $u->id,
        $u->username,
        $u->firstname,
        $u->lastname,
        $u->email
    ));
}
out('');

// --- Sections / unit maps ---
out('--- Course sections (units) ---');
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
foreach ($sections as $s) {
    $name = $s->name !== null && $s->name !== '' ? $s->name : '(unnamed)';
    $map = $DB->get_records('local_btiportal_unit', ['courseid' => $courseid, 'sectionid' => $s->id]);
    $teachers = [];
    foreach ($map as $m) {
        $tu = $DB->get_record('user', ['id' => $m->teacheruserid], 'id,username,firstname,lastname');
        $teachers[] = $tu
            ? "{$tu->username}({$tu->id})"
            : "userid={$m->teacheruserid}";
    }
    out(sprintf(
        "  sectionid=%d sectionnum=%d name=%s teachers=[%s]",
        $s->id,
        $s->section,
        $name,
        implode(', ', $teachers) ?: 'none'
    ));
}
out('');
out('  Sections named "Gradebook Unit A/B" were renamed by Part D verify — AMBIGUOUS whether to rename back.');
out('');

// --- Assignments matching test name patterns ---
out('--- Assign activities matching test name patterns ---');
$assignrows = $DB->get_records_sql(
    "SELECT a.id, a.name, a.course, cm.id AS cmid, cm.section AS sectionid
       FROM {assign} a
       JOIN {course_modules} cm ON cm.instance = a.id AND cm.deletioninprogress = 0
       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
      WHERE a.course = ?
        AND (
          a.name LIKE 'GB Unit %'
          OR a.name LIKE 'PARTA Verify Assign %'
          OR a.name LIKE 'PARTA VERIFY ASSIGN %'
          OR a.name LIKE 'PARTA%'
        )
   ORDER BY a.id",
    [$courseid]
);
if (!$assignrows) {
    out('  (none matched)');
} else {
    foreach ($assignrows as $a) {
        $gi = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $a->id,
            'itemnumber' => 0,
        ]);
        $subcount = $DB->count_records('assign_submission', ['assignment' => $a->id]);
        $gradecount = $gi ? $DB->count_records_select('grade_grades', 'itemid = ? AND (finalgrade IS NOT NULL OR rawgrade IS NOT NULL)', [$gi->id]) : 0;
        $map = $gi ? $DB->get_records('local_btiportal_unit_gradeitem', ['gradeitemid' => $gi->id]) : [];
        $meta = $DB->get_manager()->table_exists('local_btiportal_assignment_meta')
            ? $DB->get_record('local_btiportal_assignment_meta', ['assignmentid' => $a->id])
            : null;
        out(sprintf(
            "  CONFIDENT test assign: assign.id=%d cmid=%d sectionid=%d name=%s gradeitemid=%s submissions=%d graded_rows=%d unit_maps=%d meta_category=%s",
            $a->id,
            $a->cmid,
            $a->sectionid,
            $a->name,
            $gi ? (string)$gi->id : 'none',
            $subcount,
            $gradecount,
            count($map),
            $meta ? (string)$meta->category : '(no meta table/row)'
        ));
        foreach ($map as $m) {
            out(sprintf(
                "      -> unit_gradeitem mapping id=%d unitid=%d courseid=%d",
                $m->id,
                $m->unitid,
                $m->courseid
            ));
        }
    }
}
out('');

// --- Quizzes matching GB pattern ---
out('--- Quiz activities matching GB Unit pattern ---');
$quizzes = $DB->get_records_sql(
    "SELECT q.id, q.name, cm.id AS cmid, cm.section AS sectionid
       FROM {quiz} q
       JOIN {course_modules} cm ON cm.instance = q.id AND cm.deletioninprogress = 0
       JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
      WHERE q.course = ? AND q.name LIKE 'GB Unit %'
   ORDER BY q.id",
    [$courseid]
);
if (!$quizzes) {
    out('  (none matched)');
} else {
    foreach ($quizzes as $q) {
        $gi = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $q->id,
            'itemnumber' => 0,
        ]);
        $map = $gi ? $DB->get_records('local_btiportal_unit_gradeitem', ['gradeitemid' => $gi->id]) : [];
        out(sprintf(
            "  CONFIDENT test quiz: quiz.id=%d cmid=%d sectionid=%d name=%s gradeitemid=%s unit_maps=%d",
            $q->id,
            $q->cmid,
            $q->sectionid,
            $q->name,
            $gi ? (string)$gi->id : 'none',
            count($map)
        ));
    }
}
out('');

// --- Manual grade items ---
out('--- Manual grade items matching GB Unit pattern ---');
$manuals = $DB->get_records_select(
    'grade_items',
    "courseid = ? AND itemtype = 'manual' AND itemname LIKE 'GB Unit %'",
    [$courseid]
);
if (!$manuals) {
    out('  (none matched)');
} else {
    foreach ($manuals as $gi) {
        $gradecount = $DB->count_records_select(
            'grade_grades',
            'itemid = ? AND (finalgrade IS NOT NULL OR rawgrade IS NOT NULL)',
            [$gi->id]
        );
        $map = $DB->get_records('local_btiportal_unit_gradeitem', ['gradeitemid' => $gi->id]);
        out(sprintf(
            "  CONFIDENT test manual item: gradeitemid=%d name=%s graded_rows=%d unit_maps=%d",
            $gi->id,
            $gi->itemname,
            $gradecount,
            count($map)
        ));
    }
}
out('');

// --- All mapped grade items for this course (to spot orphans / unexpected) ---
out('--- All local_btiportal_unit_gradeitem rows for this course ---');
$maps = $DB->get_records('local_btiportal_unit_gradeitem', ['courseid' => $courseid], 'unitid ASC, id ASC');
if (!$maps) {
    out('  (none)');
} else {
    foreach ($maps as $m) {
        $gi = $DB->get_record('grade_items', ['id' => $m->gradeitemid]);
        $label = $gi
            ? (($gi->itemname ?: "{$gi->itemmodule}:{$gi->iteminstance}") . " type={$gi->itemtype}/{$gi->itemmodule}")
            : 'MISSING grade_items row (orphan mapping)';
        $testish = $gi && (
            stripos((string)$gi->itemname, 'GB Unit') === 0
            || stripos((string)$gi->itemname, 'PARTA') === 0
            || stripos((string)$gi->itemname, 'PARTA Verify') !== false
        );
        // Also check assign name if itemname empty
        if ($gi && $gi->itemmodule === 'assign' && $gi->iteminstance) {
            $an = $DB->get_field('assign', 'name', ['id' => $gi->iteminstance]);
            if ($an) {
                $label = "{$an} (gi.itemname=" . ($gi->itemname ?: 'empty') . ") type=mod/assign";
                $testish = $testish
                    || stripos($an, 'GB Unit') === 0
                    || stripos($an, 'PARTA') === 0;
            }
        }
        out(sprintf(
            "  [%s] map.id=%d unitid=%d gradeitemid=%d → %s",
            $testish ? 'LIKELY_TEST' : 'AMBIGUOUS/KEEP until reviewed',
            $m->id,
            $m->unitid,
            $m->gradeitemid,
            $label
        ));
    }
}
out('');

// assignment_meta table
out('--- local_btiportal_assignment_meta ---');
if ($DB->get_manager()->table_exists('local_btiportal_assignment_meta')) {
    $metas = $DB->get_records('local_btiportal_assignment_meta', ['courseid' => $courseid]);
    out('  table exists, rows for course: ' . count($metas));
    foreach ($metas as $meta) {
        out(sprintf(
            "  meta id=%d assignmentid=%d unitid=%d category=%s",
            $meta->id,
            $meta->assignmentid,
            $meta->unitid,
            $meta->category
        ));
    }
} else {
    out('  table does NOT exist in this Moodle DB (category feature not upgraded here yet)');
}
out('');

out('=== END AUDIT (no deletions performed) ===');
