<?php
/**
 * READ-ONLY: inspect assign id=8 ("intro") for cleanup decision.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

global $DB, $CFG;

$aid = 8;
$a = $DB->get_record('assign', ['id' => $aid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $aid, $a->course, false, MUST_EXIST);
$context = context_module::instance($cm->id);

echo "=== assign.id={$aid} READ-ONLY inspection ===\n";
echo "name={$a->name}\n";
echo "course={$a->course}\n";
echo "cmid={$cm->id} section={$cm->section} visible={$cm->visible}\n";
echo "timemodified=" . date('c', (int)$a->timemodified) . "\n";
echo "grade={$a->grade}\n";
echo "submissiondrafts={$a->submissiondrafts} maxattempts={$a->maxattempts} attemptreopenmethod={$a->attemptreopenmethod}\n";
echo "\n--- intro (raw) ---\n";
echo ($a->intro === null || $a->intro === '') ? "(empty)\n" : $a->intro . "\n";
echo "introformat={$a->introformat}\n";

$fs = get_file_storage();
$introfiles = $fs->get_area_files($context->id, 'mod_assign', 'intro', 0, 'itemid, filepath, filename', false);
$introatt = $fs->get_area_files($context->id, 'mod_assign', 'introattachment', 0, 'itemid, filepath, filename', false);
echo "\n--- intro editor files ---\n";
if (!$introfiles) {
    echo "(none)\n";
} else {
    foreach ($introfiles as $f) {
        echo "  {$f->get_filename()} size={$f->get_filesize()}\n";
    }
}
echo "\n--- introattachments (brief) ---\n";
if (!$introatt) {
    echo "(none)\n";
} else {
    foreach ($introatt as $f) {
        echo "  {$f->get_filename()} size={$f->get_filesize()} mimetype={$f->get_mimetype()}\n";
    }
}

$meta = $DB->get_record('local_btiportal_assignment_meta', ['assignmentid' => $aid]);
echo "\n--- assignment_meta ---\n";
if ($meta) {
    echo "category={$meta->category} unitid={$meta->unitid} courseid={$meta->courseid} timecreated=" . date('c', (int)$meta->timecreated) . "\n";
} else {
    echo "(no row)\n";
}

$subs = $DB->get_records('assign_submission', ['assignment' => $aid]);
echo "\n--- submissions count=" . count($subs) . " ---\n";
foreach ($subs as $s) {
    $u = $DB->get_record('user', ['id' => $s->userid], 'id,username,firstname,lastname');
    echo "  userid={$s->userid} " . ($u ? "{$u->username} {$u->firstname} {$u->lastname}" : '') .
        " status={$s->status} attempt={$s->attemptnumber} timemodified=" . date('c', (int)$s->timemodified) . "\n";
}

$gi = $DB->get_record('grade_items', [
    'itemtype' => 'mod',
    'itemmodule' => 'assign',
    'iteminstance' => $aid,
    'itemnumber' => 0,
]);
echo "\n--- grade_item ---\n";
if ($gi) {
    echo "id={$gi->id} itemname={$gi->itemname} grademax={$gi->grademax}\n";
    $ggs = $DB->get_records_select('grade_grades', 'itemid = ? AND (finalgrade IS NOT NULL OR rawgrade IS NOT NULL)', [$gi->id]);
    echo "graded_rows=" . count($ggs) . "\n";
    foreach ($ggs as $gg) {
        $u = $DB->get_record('user', ['id' => $gg->userid], 'id,username,firstname,lastname');
        echo "  userid={$gg->userid} " . ($u ? "{$u->username}" : '') .
            " final={$gg->finalgrade} raw={$gg->rawgrade}\n";
    }
}

// Creator hint: course_modules / logstore if available
$createdlog = $DB->get_record_sql(
    "SELECT * FROM {logstore_standard_log}
      WHERE contextinstanceid = ? AND contextlevel = ? AND action = 'created' AND target = 'course_module'
   ORDER BY timecreated ASC",
    [$cm->id, CONTEXT_MODULE],
    IGNORE_MULTIPLE
);
echo "\n--- creation log (if any) ---\n";
if ($createdlog) {
    $cu = $DB->get_record('user', ['id' => $createdlog->userid], 'id,username,firstname,lastname');
    echo "userid={$createdlog->userid} " . ($cu ? "{$cu->username} {$cu->firstname} {$cu->lastname}" : '') .
        " time=" . date('c', (int)$createdlog->timecreated) . "\n";
} else {
    echo "(none found)\n";
}

echo "\n=== END ===\n";
