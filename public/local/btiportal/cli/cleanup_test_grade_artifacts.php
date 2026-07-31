<?php
/**
 * Destructive cleanup of confirmed GB/PARTA test artifacts on course 4.
 * Hard-gated ids + name assertions. Does NOT touch assign id=8 / gi 13 / users / unit maps.
 *
 * Run:
 *   docker exec moodle-app php /var/www/html/public/local/btiportal/cli/cleanup_test_grade_artifacts.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/grade/lib.php');

global $DB, $CFG;

function out(string $s = ''): void {
    echo $s . PHP_EOL;
}
function fail(string $s): void {
    echo 'ABORT: ' . $s . PHP_EOL;
    exit(1);
}

$courseid = 4;

// Expected mapping (from audit): 5 assigns + 1 quiz + 1 manual = 7 grade items.
// NOTE: gradeitemid 8 belongs to assign.id=4 (GB Unit B), NOT assign.id=8 (that is gi 13).
$expected = [
    'assign' => [
        3 => ['gradeitemid' => 6, 'name_regex' => '/^GB Unit /'],
        4 => ['gradeitemid' => 8, 'name_regex' => '/^GB Unit /'],
        5 => ['gradeitemid' => 10, 'name_regex' => '/^PARTA /i'],
        6 => ['gradeitemid' => 11, 'name_regex' => '/^PARTA /i'],
        7 => ['gradeitemid' => 12, 'name_regex' => '/^PARTA /i'],
    ],
    'quiz' => [
        1 => ['gradeitemid' => 7, 'name_regex' => '/^GB Unit /'],
    ],
    'manual' => [
        9 => ['name_regex' => '/^GB Unit /'],
    ],
];

$mappingGiIds = [6, 7, 8, 9, 10, 11, 12];

out('=== PRE-FLIGHT MAPPING (must be 7 one-to-one) ===');
out('assign.id=3 → gi=6  (GB Unit A Assignment)');
out('quiz.id=1   → gi=7  (GB Unit A Quiz)');
out('assign.id=4 → gi=8  (GB Unit B Assignment)  ← gi 8 is NOT assign id 8');
out('manual      → gi=9  (GB Unit A Manual)');
out('assign.id=5 → gi=10 (PARTA Verify Assign 213507)');
out('assign.id=6 → gi=11 (PARTA Verify Assign 213520)');
out('assign.id=7 → gi=12 (PARTA Verify Assign 213628)');
out('KEEP: assign.id=8 → gi=13 (intro) + CAT meta');
out('');

// Verify live DB matches expected mapping before any delete.
foreach ($expected['assign'] as $aid => $spec) {
    $a = $DB->get_record('assign', ['id' => $aid, 'course' => $courseid]);
    if (!$a) {
        fail("assign.id={$aid} missing or wrong course");
    }
    if (!preg_match($spec['name_regex'], $a->name)) {
        fail("assign.id={$aid} name assertion failed: got '{$a->name}'");
    }
    $gi = $DB->get_record('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'mod',
        'itemmodule' => 'assign',
        'iteminstance' => $aid,
        'itemnumber' => 0,
    ]);
    if (!$gi || (int)$gi->id !== (int)$spec['gradeitemid']) {
        fail("assign.id={$aid} expected gi={$spec['gradeitemid']}, got " . ($gi ? $gi->id : 'none'));
    }
    out("ASSERT OK assign.id={$aid} name={$a->name} gi={$gi->id}");
}

foreach ($expected['quiz'] as $qid => $spec) {
    $q = $DB->get_record('quiz', ['id' => $qid, 'course' => $courseid]);
    if (!$q) {
        fail("quiz.id={$qid} missing or wrong course");
    }
    if (!preg_match($spec['name_regex'], $q->name)) {
        fail("quiz.id={$qid} name assertion failed: got '{$q->name}'");
    }
    $gi = $DB->get_record('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'mod',
        'itemmodule' => 'quiz',
        'iteminstance' => $qid,
        'itemnumber' => 0,
    ]);
    if (!$gi || (int)$gi->id !== (int)$spec['gradeitemid']) {
        fail("quiz.id={$qid} expected gi={$spec['gradeitemid']}, got " . ($gi ? $gi->id : 'none'));
    }
    out("ASSERT OK quiz.id={$qid} name={$q->name} gi={$gi->id}");
}

foreach ($expected['manual'] as $giid => $spec) {
    $gi = $DB->get_record('grade_items', ['id' => $giid, 'courseid' => $courseid, 'itemtype' => 'manual']);
    if (!$gi) {
        fail("manual gradeitemid={$giid} missing");
    }
    if (!preg_match($spec['name_regex'], (string)$gi->itemname)) {
        fail("manual gi={$giid} name assertion failed: got '{$gi->itemname}'");
    }
    out("ASSERT OK manual gi={$giid} name={$gi->itemname}");
}

// Guard: assign 8 / gi 13 must still exist and must NOT be in delete list.
$keep = $DB->get_record('assign', ['id' => 8, 'course' => $courseid]);
if (!$keep) {
    fail('KEEP target assign.id=8 missing before cleanup — abort');
}
$keepGi = $DB->get_record('grade_items', [
    'courseid' => $courseid,
    'itemtype' => 'mod',
    'itemmodule' => 'assign',
    'iteminstance' => 8,
    'itemnumber' => 0,
]);
if (!$keepGi || (int)$keepGi->id !== 13) {
    fail('KEEP target assign.id=8 expected gi=13, got ' . ($keepGi ? $keepGi->id : 'none'));
}
if (in_array(13, $mappingGiIds, true)) {
    fail('Safety: gi 13 must not be in mapping cleanup list');
}
out("KEEP OK assign.id=8 name={$keep->name} gi=13");
out('');

out('=== EXECUTING DELETES ===');

foreach (array_keys($expected['assign']) as $aid) {
    $cm = get_coursemodule_from_instance('assign', $aid, $courseid, false, MUST_EXIST);
    out("course_delete_module(cmid={$cm->id}) for assign.id={$aid}");
    course_delete_module($cm->id);
}

foreach (array_keys($expected['quiz']) as $qid) {
    $cm = get_coursemodule_from_instance('quiz', $qid, $courseid, false, MUST_EXIST);
    out("course_delete_module(cmid={$cm->id}) for quiz.id={$qid}");
    course_delete_module($cm->id);
}

foreach (array_keys($expected['manual']) as $giid) {
    $gradeitem = \grade_item::fetch(['id' => $giid]);
    if (!$gradeitem) {
        fail("grade_item::fetch failed for gi={$giid} at delete time");
    }
    out("grade_item::delete() for manual gi={$giid} name={$gradeitem->itemname}");
    $gradeitem->delete('local_btiportal_cleanup');
}

out('delete_records_list local_btiportal_unit_gradeitem gradeitemid IN (' . implode(',', $mappingGiIds) . ')');
$DB->delete_records_list('local_btiportal_unit_gradeitem', 'gradeitemid', $mappingGiIds);

rebuild_course_cache($courseid, true);
out('rebuild_course_cache(4, true) done');
out('');

out('=== POST-CHECK ===');

foreach ([3, 4, 5, 6, 7] as $aid) {
    $gone = !$DB->record_exists('assign', ['id' => $aid]);
    out($gone ? "GONE assign.id={$aid}" : "STILL EXISTS assign.id={$aid} — FAIL");
    if (!$gone) {
        fail("assign.id={$aid} still exists");
    }
}
$quizGone = !$DB->record_exists('quiz', ['id' => 1]);
out($quizGone ? 'GONE quiz.id=1' : 'STILL EXISTS quiz.id=1 — FAIL');
if (!$quizGone) {
    fail('quiz.id=1 still exists');
}
$manualGone = !$DB->record_exists('grade_items', ['id' => 9]);
out($manualGone ? 'GONE manual gi=9' : 'STILL EXISTS manual gi=9 — FAIL');
if (!$manualGone) {
    fail('manual gi=9 still exists');
}

foreach ($mappingGiIds as $giid) {
    $orphanMap = $DB->record_exists('local_btiportal_unit_gradeitem', ['gradeitemid' => $giid]);
    $orphanGi = $DB->record_exists('grade_items', ['id' => $giid]);
    $orphanGg = $DB->count_records('grade_grades', ['itemid' => $giid]);
    out("gi={$giid}: mapping=" . ($orphanMap ? 'ORPHAN' : 'clean') .
        " grade_items=" . ($orphanGi ? 'STILL_THERE' : 'gone') .
        " grade_grades_rows={$orphanGg}");
    if ($orphanMap || $orphanGi || $orphanGg > 0) {
        fail("orphaned data for deleted gi={$giid}");
    }
}

$a8 = $DB->get_record('assign', ['id' => 8], '*', MUST_EXIST);
$gi13 = $DB->get_record('grade_items', ['id' => 13], '*', MUST_EXIST);
$meta = $DB->get_record('local_btiportal_assignment_meta', ['assignmentid' => 8]);
$map13 = $DB->get_record('local_btiportal_unit_gradeitem', ['gradeitemid' => 13]);
out("KEEP intact: assign.id=8 name={$a8->name}");
out("KEEP intact: gi=13 itemname={$gi13->itemname} instance={$gi13->iteminstance}");
out('KEEP intact: meta=' . ($meta ? "category={$meta->category}" : 'MISSING'));
out('KEEP intact: unit_gradeitem for gi13=' . ($map13 ? "map.id={$map13->id} unitid={$map13->unitid}" : 'MISSING'));

$users = ['portal_instructor', 'portal_instructor2', 'portal_student', 'brianmuriuki03', 'qamarbishar121'];
foreach ($users as $un) {
    $u = $DB->get_record('user', ['username' => $un, 'deleted' => 0]);
    out($u ? "USER OK {$un} id={$u->id}" : "USER MISSING {$un}");
    if (!$u) {
        fail("user {$un} missing/deleted");
    }
}

foreach ([10, 11, 12] as $sectionid) {
    $maps = $DB->get_records('local_btiportal_unit', ['courseid' => $courseid, 'sectionid' => $sectionid]);
    out("unit map sectionid={$sectionid} rows=" . count($maps));
    if (count($maps) < 1) {
        fail("teacher map missing for section {$sectionid}");
    }
}

// Remaining mapped items for unit 10 (Database Management) — should be intro only ideally
$remaining = $DB->get_records('local_btiportal_unit_gradeitem', ['courseid' => $courseid], 'id ASC');
out('Remaining unit_gradeitem rows for course 4: ' . count($remaining));
foreach ($remaining as $m) {
    $gi = $DB->get_record('grade_items', ['id' => $m->gradeitemid]);
    $label = $gi ? (($gi->itemname ?: "{$gi->itemmodule}:{$gi->iteminstance}") . " type={$gi->itemtype}/{$gi->itemmodule}") : 'MISSING gi';
    out("  map.id={$m->id} unitid={$m->unitid} gi={$m->gradeitemid} → {$label}");
}

out('');
out('=== CLEANUP COMPLETE ===');
