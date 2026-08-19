<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\academic_enrolment_helper;
use local_btiportal\curriculum_unit_helper;
use local_btiportal\enrolment_transition_helper;
use local_btiportal\student_unit_pathway_helper;

global $DB;

$userid = (int)($argv[1] ?? 34);
$execute = in_array('--execute', $argv, true);

cli_writeln('Migrating course_unit home curricula into curriculum_unit assignments…');
$inserted = curriculum_unit_helper::migrate_from_course_units();
cli_writeln("assignments inserted={$inserted}");

$before = $DB->get_records_sql(
    "SELECT e.courseid, c.shortname, ue.status
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE ue.userid = :uid AND c.id <> :site
   ORDER BY c.shortname",
    ['uid' => $userid, 'site' => SITEID]
);
cli_heading('Enrolments before');
if (!$before) {
    cli_writeln('(none)');
} else {
    foreach ($before as $row) {
        cli_writeln("  {$row->courseid} {$row->shortname} status={$row->status}");
    }
}

$pathway = student_unit_pathway_helper::get_pathway($userid);
$all = array_merge($pathway['current'] ?? [], $pathway['studied'] ?? [], $pathway['upcoming'] ?? []);
cli_heading('Expected after assignment sync');
foreach ($all as $unit) {
    $cid = (int)($unit['moodlecourseid'] ?? 0);
    cli_writeln(sprintf('%s | moodle=%s | map=%s', $unit['code'] ?? '-', $cid ?: '-', $unit['mappingstatus'] ?? '-'));
}

if (!$execute) {
    cli_writeln('Preview only. Re-run with --execute to enrol.');
    exit(0);
}

$admin = get_admin();
$result = academic_enrolment_helper::execute($userid, 'manual_reconciliation', [], (int)$admin->id);
if (empty($result['ok'])) {
    cli_error('execute failed: ' . json_encode($result['errors'] ?? []));
}
$r = $result['result'];
cli_heading('Execution');
cli_writeln('status=' . ($r['status'] ?? ''));
cli_writeln('newly=' . count($r['enrolled'] ?? []));
foreach ($r['enrolled'] ?? [] as $row) {
    cli_writeln('  + ' . ($row['code'] ?? '') . ' -> ' . ($row['moodlecourseid'] ?? 0) . ' ' . ($row['message'] ?? ''));
}
cli_writeln('already=' . count($r['alreadyenrolled'] ?? []));
cli_writeln('failed=' . count($r['failed'] ?? []));
foreach ($r['failed'] ?? [] as $row) {
    cli_writeln('  ! ' . ($row['code'] ?? '') . ' ' . ($row['message'] ?? ''));
}

cli_heading('Verify enrolments');
foreach ($all as $unit) {
    $cid = (int)($unit['moodlecourseid'] ?? 0);
    if ($cid <= 1) {
        continue;
    }
    $ok = is_enrolled(\context_course::instance($cid), $userid, '', true) ? 'YES' : 'NO';
    $list = enrolment_transition_helper::list_user_enrolments($userid);
    cli_writeln(($unit['code'] ?? '') . " course {$cid} enrolled={$ok}");
}

$after = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT c.id)
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE ue.userid = ? AND ue.status = 0 AND c.id <> ?",
    [$userid, SITEID]
);
cli_writeln("Total active Moodle courses after sync: {$after}");
