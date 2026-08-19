<?php
/**
 * Preview and optionally execute idempotent Moodle enrolment sync for an existing student.
 *
 * Usage:
 *   php local/btiportal/cli/sync_existing_student_preview.php [--execute] [--userid=NNN]
 */
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\academic_enrolment_helper;
use local_btiportal\student_portal_helper;
use local_btiportal\student_profile_helper;

global $DB;

$execute = in_array('--execute', $argv, true);
$userid = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--userid=')) {
        $userid = (int)substr($arg, 9);
    }
}

if ($userid <= 0) {
    cli_heading('Students with local_btiportal profiles');
    $rows = $DB->get_records_sql(
        "SELECT sp.id AS profileid, sp.userid, u.firstname, u.lastname, u.email, u.username, u.idnumber,
                sp.programmeid, sp.curriculumid, sp.curriculumyearid, sp.curriculumsemesterid,
                sp.academicstatus, sp.timemodified
           FROM {local_btiportal_student_profile} sp
           JOIN {user} u ON u.id = sp.userid AND u.deleted = 0
       ORDER BY sp.timemodified DESC",
        [],
        0,
        20
    );
    foreach ($rows as $r) {
        cli_writeln(sprintf(
            'userid=%d profile=%d name=%s %s email=%s idnumber=%s programme=%d curriculum=%d year=%d sem=%d modified=%s',
            (int)$r->userid,
            (int)$r->profileid,
            $r->firstname,
            $r->lastname,
            $r->email,
            $r->idnumber ?: '-',
            (int)$r->programmeid,
            (int)$r->curriculumid,
            (int)$r->curriculumyearid,
            (int)$r->curriculumsemesterid,
            userdate((int)$r->timemodified)
        ));
    }
    cli_writeln('');
    cli_writeln('Re-run with: php local/btiportal/cli/sync_existing_student_preview.php --userid=NNN [--execute]');
    exit(0);
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
if (!$user) {
    cli_error("Moodle user {$userid} not found or deleted.");
}

$rawprofile = student_profile_helper::get_by_userid($userid);
if (!$rawprofile) {
    cli_error("No local_btiportal student profile for Moodle userid {$userid}.");
}

$profile = student_portal_helper::enrich_profile($rawprofile);
$blockers = [];
if ((int)$profile['programmeid'] <= 0) {
    $blockers[] = 'programmeid missing';
}
if ((int)$profile['curriculumid'] <= 0) {
    $blockers[] = 'curriculumid missing';
}
if ((int)$profile['curriculumyearid'] <= 0) {
    $blockers[] = 'curriculum year missing';
}
if ((int)$profile['curriculumsemesterid'] <= 0) {
    $blockers[] = 'curriculum semester missing';
}

cli_heading('Student');
cli_writeln('Name: ' . fullname($user));
cli_writeln('Moodle userid: ' . $userid);
cli_writeln('Email: ' . $user->email);
cli_writeln('Username: ' . $user->username);
cli_writeln('Admission number: ' . ($user->idnumber ?: '(none)'));

cli_heading('Academic placement');
cli_writeln('Programme: ' . ($profile['programmename'] ?: $profile['programmecode'] ?: $profile['programmeid']));
cli_writeln('Curriculum: ' . ($profile['curriculumname'] ?: $profile['curriculumid']));
cli_writeln('Year: ' . ($profile['curriculumyearlabel'] ?: $profile['yearofstudy'] ?: $profile['curriculumyearid']));
cli_writeln('Semester: ' . ($profile['curriculumsemesterlabel'] ?: $profile['semestersequence'] ?: $profile['curriculumsemesterid']));

if ($blockers) {
    cli_error('Incomplete academic placement — cannot sync: ' . implode('; ', $blockers));
}

$preview = academic_enrolment_helper::preview($userid, 'manual_reconciliation');
if (!$preview['ok']) {
    $msgs = array_map(static fn($e) => ($e['field'] ?? '') . ': ' . ($e['message'] ?? ''), $preview['errors'] ?? []);
    cli_error('Preview failed: ' . implode(' | ', $msgs));
}
$p = $preview['preview'];

cli_heading('Expected Course Units (auto-enrolable delivery plan)');
$idx = 1;
foreach (array_merge($p['alreadyenrolled'] ?? [], $p['toenrol'] ?? [], $p['skipped'] ?? [], $p['manualreview'] ?? []) as $row) {
    cli_writeln(sprintf('%d. %s — %s', $idx++, $row['code'] ?: '-', $row['name'] ?: '-'));
}

cli_heading('Mapped Moodle Courses');
$idx = 1;
foreach (array_merge($p['alreadyenrolled'] ?? [], $p['toenrol'] ?? []) as $row) {
    if ((int)($row['moodlecourseid'] ?? 0) <= 1) {
        continue;
    }
    cli_writeln(sprintf(
        '%d. %s → Moodle course %d (%s)',
        $idx++,
        $row['code'] ?: $row['name'],
        (int)$row['moodlecourseid'],
        $row['moodlecoursename'] ?: '-'
    ));
}

cli_heading('Already enrolled');
if (empty($p['alreadyenrolled'])) {
    cli_writeln('(none)');
} else {
    foreach ($p['alreadyenrolled'] as $row) {
        cli_writeln(sprintf('- %s → course %d (%s)', $row['code'], (int)$row['moodlecourseid'], $row['reason']));
    }
}

cli_heading('Missing (to enrol)');
if (empty($p['toenrol'])) {
    cli_writeln('(none)');
} else {
    foreach ($p['toenrol'] as $row) {
        cli_writeln(sprintf('- %s → course %d (%s)', $row['code'], (int)$row['moodlecourseid'], $row['reason'] ?: 'not enrolled'));
    }
}

cli_heading('Unmapped');
if (empty($p['skipped'])) {
    cli_writeln('(none)');
} else {
    foreach ($p['skipped'] as $row) {
        cli_writeln(sprintf('- %s — %s', $row['code'] ?: $row['name'], $row['reason']));
    }
}

cli_heading('Manual review');
if (empty($p['manualreview'])) {
    cli_writeln('(none)');
} else {
    foreach ($p['manualreview'] as $row) {
        cli_writeln(sprintf('- %s — %s', $row['code'] ?: $row['name'], $row['reason']));
    }
}

if (!empty($p['blockers'])) {
    cli_error('Blockers prevent execution: ' . json_encode($p['blockers']));
}

if (!$execute) {
    cli_writeln('');
    cli_writeln('Preview only. To execute idempotent sync: add --execute');
    exit(0);
}

cli_heading('Executing manual_reconciliation enrolment');
$admin = get_admin();
$result = academic_enrolment_helper::execute($userid, 'manual_reconciliation', [], (int)$admin->id);
if (!$result['ok']) {
    $msgs = array_map(static fn($e) => ($e['field'] ?? '') . ': ' . ($e['message'] ?? ''), $result['errors'] ?? []);
    cli_error('Execution failed: ' . implode(' | ', $msgs));
}
$r = $result['result'];

cli_heading('Execution result');
cli_writeln('Status: ' . ($r['status'] ?? 'unknown'));
cli_writeln('Student Moodle userid: ' . $userid);
cli_writeln('Newly enrolled: ' . count($r['enrolled'] ?? []));
foreach ($r['enrolled'] ?? [] as $row) {
    cli_writeln(sprintf('  + %s → course %d (%s)', $row['code'], (int)$row['moodlecourseid'], $row['message'] ?? ''));
}
cli_writeln('Already enrolled (reused): ' . count($r['alreadyenrolled'] ?? []));
cli_writeln('Unmapped: ' . count($r['skipped'] ?? []));
cli_writeln('Failed: ' . count($r['failed'] ?? []));
foreach ($r['failed'] ?? [] as $row) {
    cli_writeln(sprintf('  ! %s → course %d (%s)', $row['code'], (int)$row['moodlecourseid'], $row['message'] ?? ''));
}

$enrolcount = (int)$DB->get_field_sql(
    "SELECT COUNT(DISTINCT c.id)
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE ue.userid = ? AND ue.status = 0 AND c.id <> ?",
    [$userid, SITEID]
);
cli_writeln('Total Moodle courses after sync: ' . $enrolcount);
