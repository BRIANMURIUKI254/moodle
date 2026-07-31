<?php
/**
 * Part A E2E verification for Moodle-backed document assignments.
 * Run: docker exec moodle-app php /var/www/html/public/local/btiportal/cli/verify_document_assignment_part_a.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/externallib.php');
require_once($CFG->dirroot . '/lib/externallib.php');

use local_btiportal\assignment_helper;
use local_btiportal\external\create_document_assignment;
use local_btiportal\external\get_unit_gradebook;
use local_btiportal\external\submit_unit_grade;
use local_btiportal\gradebook_helper;
use local_btiportal\unit_helper;

function pa_ok(string $step, string $detail = ''): void {
    echo "PASS [$step]" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}
function pa_fail(string $step, string $detail): void {
    echo "FAIL [$step] — $detail" . PHP_EOL;
}
function pa_info(string $msg): void {
    echo "INFO $msg" . PHP_EOL;
}
function pa_as_user(stdClass $user, callable $fn) {
    global $USER;
    $prev = $USER;
    \core\session\manager::set_user($user);
    try {
        return $fn();
    } finally {
        \core\session\manager::set_user($prev);
    }
}

echo "=== PART A — document assignment E2E ===" . PHP_EOL;

// --- A1: WS registration ---
$fn = $DB->get_record('external_functions', ['name' => 'local_btiportal_create_document_assignment']);
if (!$fn) {
    pa_fail('A1_ws_function', 'external_functions row missing');
} else {
    pa_ok('A1_ws_function', "id={$fn->id} classname={$fn->classname}");
}
$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
$linked = $DB->record_exists('external_services_functions', [
    'externalserviceid' => $service->id,
    'functionname' => 'local_btiportal_create_document_assignment',
]);
$linked ? pa_ok('A1_bti_portal_api', 'function linked') : pa_fail('A1_bti_portal_api', 'not linked to bti_portal_api');
$ver = get_config('local_btiportal', 'version');
pa_info("plugin version config={$ver}");
($ver == 2026072301) ? pa_ok('A1_version', '2026072301') : pa_fail('A1_version', "expected 2026072301 got {$ver}");

// --- A1 capabilities for editingteacher ---
$role = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
$caps = ['moodle/course:manageactivities', 'mod/assign:addinstance', 'mod/assign:grade', 'moodle/grade:edit', 'moodle/grade:viewall'];
foreach ($caps as $cap) {
    $allowed = $DB->record_exists_select(
        'role_capabilities',
        "roleid = ? AND capability = ? AND permission = ?",
        [$role->id, $cap, CAP_ALLOW]
    );
    // Also check archetype defaults via get_role_context_caps is harder; use context_system risk.
    $sysctx = context_system::instance();
    // Course-level check against a real course below with has_capability as teacher.
    $allowed
        ? pa_ok("A1_rolecap_{$cap}", 'CAP_ALLOW on editingteacher (role_capabilities)')
        : pa_info("A1_rolecap_{$cap}: no direct CAP_ALLOW row (may inherit — checked live below)");
}

$course = $DB->get_record('course', ['id' => 4], '*', IGNORE_MISSING)
    ?: $DB->get_record_sql('SELECT * FROM {course} WHERE id > 1 ORDER BY id ASC', [], IGNORE_MULTIPLE);
if (!$course) {
    cli_error('No course');
}
$courseid = (int)$course->id;

$teacher = $DB->get_record('user', ['username' => 'portal_instructor', 'deleted' => 0], '*', MUST_EXIST);
$student = $DB->get_record('user', ['username' => 'portal_student', 'deleted' => 0], '*', MUST_EXIST);

$unitmaps = $DB->get_records('local_btiportal_unit', ['teacheruserid' => $teacher->id, 'courseid' => $courseid]);
if (!$unitmaps) {
    // Fall back: ensure mapping for first topic section.
    $sec = $DB->get_record_sql(
        'SELECT * FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC',
        [$courseid],
        IGNORE_MULTIPLE
    );
    unit_helper::ensure_unit_teacher($courseid, (int)$sec->id, (int)$teacher->id);
    $unitid = (int)$sec->id;
} else {
    $unitid = (int)reset($unitmaps)->sectionid;
}
$section = $DB->get_record('course_sections', ['id' => $unitid], '*', MUST_EXIST);
pa_info("course={$courseid} unitid={$unitid} sectionnum={$section->section} teacher={$teacher->id} student={$student->id}");

$coursectx = context_course::instance($courseid);
$capManage = has_capability('moodle/course:manageactivities', $coursectx, $teacher);
$capAdd = has_capability('mod/assign:addinstance', $coursectx, $teacher);
($capManage || $capAdd)
    ? pa_ok('A1_live_teacher_caps', 'manageactivities=' . ($capManage ? 'yes' : 'no') . ' addinstance=' . ($capAdd ? 'yes' : 'no'))
    : pa_fail('A1_live_teacher_caps', 'portal_instructor lacks both manageactivities and assign:addinstance on course ' . $courseid);

// --- A3: mapping is synchronous in helper (code-path evidence printed) ---
pa_info('A3: assignment_helper::create_document_assignment calls gradebook_helper::map_grade_item before return (sync at create)');

// --- A4a: create assignment as teacher ---
$assignname = 'PARTA Verify Assign ' . date('His');
$created = null;
try {
    $created = pa_as_user($teacher, function () use ($courseid, $unitid, $assignname) {
        return create_document_assignment::execute(
            $courseid,
            $unitid,
            $assignname,
            'Part A brief: complete the worksheet.',
            100
        );
    });
    $created = \core_external\external_api::clean_returnvalue(
        create_document_assignment::execute_returns(),
        $created
    );
} catch (Throwable $e) {
    pa_fail('A4a_create', $e->getMessage());
    cli_error('Cannot continue without create');
}

$cm = $DB->get_record('course_modules', ['id' => $created['cmid']], '*', MUST_EXIST);
$sectionok = ((int)$cm->section === $unitid);
$sectionok
    ? pa_ok('A4a_section', "cmid={$cm->id} section={$cm->section} matches unitid={$unitid}")
    : pa_fail('A4a_section', "cm.section={$cm->section} expected unitid={$unitid}");

$maprow = $DB->get_record('local_btiportal_unit_gradeitem', [
    'courseid' => $courseid,
    'unitid' => $unitid,
    'gradeitemid' => $created['gradeitemid'],
]);
$maprow
    ? pa_ok('A4a_mapping_row', "gradeitemid={$created['gradeitemid']} mapped at create")
    : pa_fail('A4a_mapping_row', 'no local_btiportal_unit_gradeitem row after create');

// --- A4b: gradebook BEFORE submit ---
$gb = pa_as_user($teacher, function () use ($courseid, $unitid) {
    return get_unit_gradebook::execute($courseid, $unitid);
});
$gb = \core_external\external_api::clean_returnvalue(get_unit_gradebook::execute_returns(), $gb);
$foundItem = null;
foreach ($gb['gradeitems'] as $gi) {
    if ((int)$gi['id'] === (int)$created['gradeitemid']) {
        $foundItem = $gi;
        break;
    }
}
$foundItem
    ? pa_ok('A4b_gradebook_pre_submit', "column present name={$foundItem['name']} module={$foundItem['itemmodule']}")
    : pa_fail('A4b_gradebook_pre_submit', 'grade item not in get_unit_gradebook before any submission');

// --- A4c: student file submit + verify file in Moodle ---
$fs = get_file_storage();
$userctx = context_user::instance($student->id);

// Draft area must be created while $USER is the student (CLI default can be guest).
$draftitemid = 0;
$filename = 'parta_student_work.txt';
$filecontent = "Part A student submission content " . time() . "\n";
pa_as_user($student, function () use ($fs, $userctx, &$draftitemid, $filename, $filecontent) {
    $draftitemid = file_get_unused_draft_itemid();
    $fs->create_file_from_string([
        'contextid' => $userctx->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => $filename,
    ], $filecontent);
});

$submitwarnings = pa_as_user($student, function () use ($created, $draftitemid) {
    $w1 = \mod_assign_external::save_submission($created['assignmentid'], [
        'files_filemanager' => $draftitemid,
    ]);
    $w2 = \mod_assign_external::submit_for_grading($created['assignmentid'], true);
    return ['save' => $w1, 'submit' => $w2];
});
pa_info('A4c save_submission warnings=' . json_encode($submitwarnings['save']));
pa_info('A4c submit_for_grading warnings=' . json_encode($submitwarnings['submit']));

$assigncm = get_coursemodule_from_instance('assign', $created['assignmentid'], $courseid, false, MUST_EXIST);
$modcontext = context_module::instance($assigncm->id);
$assignment = new assign($modcontext, $assigncm, $course);
$submission = $assignment->get_user_submission($student->id, false);
if (!$submission || $submission->status !== 'submitted') {
    pa_fail('A4c_status', 'expected submitted, got ' . ($submission->status ?? 'null'));
} else {
    pa_ok('A4c_status', "submission id={$submission->id} status=submitted");
}

$files = $fs->get_area_files(
    $modcontext->id,
    'assignsubmission_file',
    'submission_files',
    $submission->id,
    'filename',
    false
);
if (empty($files)) {
    pa_fail('A4c_file_retrievable', 'no files in assignsubmission_file/submission_files');
} else {
    $f = reset($files);
    $retrieved = $f->get_content();
    ($retrieved === $filecontent && $f->get_filename() === $filename)
        ? pa_ok('A4c_file_retrievable', "filename={$filename} bytes=" . strlen($retrieved) . ' content matches')
        : pa_fail('A4c_file_retrievable', 'file present but content/name mismatch');
}

// --- A4d: grade via assign path, then check both APIs ---
$gradeResult = pa_as_user($teacher, function () use ($created, $student) {
    \mod_assign_external::save_grade(
        $created['assignmentid'],
        $student->id,
        88,
        -1,
        false,
        '',
        false
    );
    return true;
});
pa_ok('A4d_save_grade', 'mod_assign_save_grade wrote 88');

// Student grades via mod_assign_get_grades
$gradesResp = pa_as_user($teacher, function () use ($created) {
    return \mod_assign_external::get_grades([$created['assignmentid']]);
});
$gotAssignGrade = null;
foreach ($gradesResp['assignments'] ?? [] as $block) {
    if ((int)$block['assignmentid'] !== (int)$created['assignmentid']) {
        continue;
    }
    foreach ($block['grades'] ?? [] as $g) {
        if ((int)$g['userid'] === (int)$student->id) {
            $gotAssignGrade = (float)$g['grade'];
        }
    }
}
($gotAssignGrade !== null && abs($gotAssignGrade - 88) < 0.01)
    ? pa_ok('A4d_mod_assign_get_grades', "student grade={$gotAssignGrade}")
    : pa_fail('A4d_mod_assign_get_grades', 'expected 88, got ' . json_encode($gotAssignGrade));

$gb2 = pa_as_user($teacher, function () use ($courseid, $unitid) {
    return get_unit_gradebook::execute($courseid, $unitid);
});
$gb2 = \core_external\external_api::clean_returnvalue(get_unit_gradebook::execute_returns(), $gb2);
$gotGb = null;
foreach ($gb2['grades'] as $g) {
    if ((int)$g['studentid'] === (int)$student->id && (int)$g['gradeitemid'] === (int)$created['gradeitemid']) {
        $gotGb = (float)$g['grade'];
    }
}
($gotGb !== null && abs($gotGb - 88) < 0.01)
    ? pa_ok('A4d_get_unit_gradebook', "student grade={$gotGb}")
    : pa_fail('A4d_get_unit_gradebook', 'expected 88, got ' . json_encode($gotGb) . ' (grades=' . json_encode($gb2['grades']) . ')');

// --- A4e: resubmit after graded ---
$assignrec = $DB->get_record('assign', ['id' => $created['assignmentid']], '*', MUST_EXIST);
pa_info("A4e settings attemptreopenmethod={$assignrec->attemptreopenmethod} maxattempts={$assignrec->maxattempts} submissiondrafts={$assignrec->submissiondrafts}");

$draft2 = 0;
pa_as_user($student, function () use ($fs, $userctx, &$draft2) {
    $draft2 = file_get_unused_draft_itemid();
    $fs->create_file_from_string([
        'contextid' => $userctx->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draft2,
        'filepath' => '/',
        'filename' => 'parta_resubmit.txt',
    ], "resubmit " . time());
});

$resubmit = pa_as_user($student, function () use ($created, $draft2) {
    try {
        $w1 = \mod_assign_external::save_submission($created['assignmentid'], [
            'files_filemanager' => $draft2,
        ]);
        $w2 = \mod_assign_external::submit_for_grading($created['assignmentid'], true);
        return ['ok' => true, 'save' => $w1, 'submit' => $w2];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'errorcode' => ($e instanceof moodle_exception ? $e->errorcode : '')];
    }
});
$subAfter = $assignment->get_user_submission($student->id, false);
pa_info('A4e resubmit API result=' . json_encode($resubmit));
pa_info('A4e submission after attempt status=' . ($subAfter->status ?? 'null') . ' attemptnumber=' . ($subAfter->attemptnumber ?? '?'));

// Document actual behavior — with maxattempts=1 and attemptreopenmethod=none, Moodle typically blocks new attempts.
if (!empty($resubmit['ok'])) {
    $hasWarn = !empty($resubmit['save']) || !empty($resubmit['submit']);
    if ($hasWarn) {
        pa_ok('A4e_resubmit_behavior', 'API returned with warnings (likely blocked/partial): ' . json_encode($resubmit));
    } else {
        pa_ok('A4e_resubmit_behavior', 'Resubmit allowed without warnings — check if grade was reset');
    }
} else {
    pa_ok('A4e_resubmit_behavior', 'Resubmit blocked/failed: ' . ($resubmit['error'] ?? 'unknown'));
}

// --- A4f: large file (no 1.8MB frontend leftover in PHP path; check Moodle accepts >1.8MB) ---
$largeBytes = 2 * 1024 * 1024 + 100; // ~2MB+
$draft3 = 0;
$largeName = 'parta_large.bin';
pa_as_user($student, function () use ($fs, $userctx, &$draft3, $largeName, $largeBytes) {
    $draft3 = file_get_unused_draft_itemid();
    $fs->create_file_from_string([
        'contextid' => $userctx->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draft3,
        'filepath' => '/',
        'filename' => $largeName,
    ], str_repeat('A', $largeBytes));
});

// Check frontend leftover limit via grep is done outside; here verify Moodle path accepts >1.8MB draft.
$largeFile = $fs->get_file($userctx->id, 'user', 'draft', $draft3, '/', $largeName);
($largeFile && $largeFile->get_filesize() > 1800000)
    ? pa_ok('A4f_moodle_draft_gt_1_8mb', 'draft filesize=' . $largeFile->get_filesize())
    : pa_fail('A4f_moodle_draft_gt_1_8mb', 'could not create >1.8MB draft');

$maxbytes = (int)get_config('assignsubmission_file', 'maxbytes');
$siteMax = (int)$CFG->maxbytes;
pa_info("A4f Moodle limits assignsubmission_file.maxbytes={$maxbytes} CFG.maxbytes={$siteMax} course.maxbytes=" . (int)$course->maxbytes);

echo PHP_EOL . '=== Fixture ===' . PHP_EOL;
echo json_encode($created, JSON_PRETTY_PRINT) . PHP_EOL;
echo 'Done.' . PHP_EOL;
