<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\academic_enrolment_helper;
use local_btiportal\student_portal_helper;
use local_btiportal\student_profile_helper;
use local_btiportal\student_unit_pathway_helper;
use local_btiportal\curriculum_delivery_helper;

global $DB;

$userid = (int)($argv[1] ?? 0);
if ($userid <= 0) {
    $row = $DB->get_record_sql(
        "SELECT sp.userid
           FROM {local_btiportal_student_profile} sp
           JOIN {user} u ON u.id = sp.userid AND u.deleted = 0
       ORDER BY sp.timemodified DESC",
        [],
        0,
        1
    );
    $userid = $row ? (int)$row->userid : 0;
}
if ($userid <= 0) {
    cli_error('No student profile found.');
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$profile = student_profile_helper::get_by_userid($userid);
if (!$profile) {
    cli_error("No local_btiportal profile for userid {$userid}");
}
$enriched = student_portal_helper::enrich_profile($profile);

cli_heading('Student');
cli_writeln('Moodle userid: ' . $userid);
cli_writeln('Name: ' . fullname($user));
cli_writeln('Email: ' . $user->email);
cli_writeln('Username: ' . $user->username);
cli_writeln('Admission number: ' . ($user->idnumber ?: '(none)'));

cli_heading('Curriculum');
cli_writeln('Programme: ' . ($enriched['programmename'] ?? $profile['programmeid']));
cli_writeln('Curriculum: ' . ($enriched['curriculumname'] ?? $profile['curriculumid']));
cli_writeln('Curriculum id: ' . (int)$profile['curriculumid']);
cli_writeln('Year: ' . ($enriched['curriculumyearlabel'] ?? $profile['curriculumyearid']));
cli_writeln('Semester: ' . ($enriched['curriculumsemesterlabel'] ?? $profile['curriculumsemesterid']));

$cu = $DB->count_records('local_btiportal_course_unit');
$assign = $DB->count_records('local_btiportal_curriculum_unit');
cli_writeln("Catalogue course_units={$cu} curriculum_unit assignments={$assign}");

$tree = curriculum_delivery_helper::get_curriculum_delivery((int)$profile['curriculumid']);
cli_writeln('Delivery ok=' . (!empty($tree['ok']) ? '1' : '0'));
$treeunits = 0;
foreach ($tree['record']['years'] ?? [] as $year) {
    foreach ($year['semesters'] ?? [] as $sem) {
        $treeunits += count($sem['courseunits'] ?? []);
        foreach ($sem['courseunits'] ?? [] as $u) {
            cli_writeln(sprintf(
                '  TREE Y%s S%s %s | moodle=%s | map=%s | courseid=%s',
                $year['yearnumber'] ?? '-',
                $sem['semesternumber'] ?? '-',
                $u['code'] ?? '-',
                $u['moodlecourseid'] ?? ($u['courseid'] ?? '-'),
                $u['mappingstatus'] ?? '-',
                $u['courseid'] ?? '-'
            ));
        }
    }
}
cli_writeln("Tree course units={$treeunits}");

$pathway = student_unit_pathway_helper::get_pathway($userid);
$all = array_merge($pathway['current'] ?? [], $pathway['studied'] ?? [], $pathway['upcoming'] ?? []);
cli_heading('Expected Course Units (pathway)');
cli_writeln('current=' . count($pathway['current'] ?? []) . ' studied=' . count($pathway['studied'] ?? []) . ' upcoming=' . count($pathway['upcoming'] ?? []));

cli_heading('Course Unit | Moodle ID | Mapping | Student enrolled');
foreach ($all as $unit) {
    $cid = (int)($unit['moodlecourseid'] ?? 0);
    $enrolled = 0;
    if ($cid > 1) {
        try {
            $enrolled = is_enrolled(\context_course::instance($cid), $userid, '', true) ? 1 : 0;
        } catch (Throwable $e) {
            $enrolled = 0;
        }
    }
    cli_writeln(sprintf(
        '%s | %s | %s | %s',
        $unit['code'] ?: '-',
        $cid ?: '-',
        $unit['mappingstatus'] ?? '-',
        $enrolled ? 'YES' : 'NO'
    ));
}

$ues = $DB->get_records_sql(
    "SELECT e.courseid, c.shortname, c.fullname, ue.status
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE ue.userid = :uid AND c.id <> :site
   ORDER BY c.shortname",
    ['uid' => $userid, 'site' => SITEID]
);
cli_heading('Actual Moodle enrolments before');
if (!$ues) {
    cli_writeln('(none)');
} else {
    foreach ($ues as $row) {
        cli_writeln(sprintf('  %d %s status=%s', $row->courseid, $row->shortname, $row->status));
    }
}

$preview = academic_enrolment_helper::preview($userid, 'manual_reconciliation');
cli_heading('Enrolment preview');
if (empty($preview['ok'])) {
    cli_writeln('preview failed: ' . json_encode($preview['errors'] ?? []));
} else {
    $p = $preview['preview'];
    cli_writeln('toenrol=' . count($p['toenrol'] ?? []));
    cli_writeln('already=' . count($p['alreadyenrolled'] ?? []));
    cli_writeln('skipped=' . count($p['skipped'] ?? []));
    foreach ($p['toenrol'] ?? [] as $row) {
        cli_writeln('  MISSING ' . ($row['code'] ?? '') . ' -> ' . ($row['moodlecourseid'] ?? 0));
    }
    foreach ($p['skipped'] ?? [] as $row) {
        cli_writeln('  UNMAPPED ' . ($row['code'] ?? '') . ' ' . ($row['reason'] ?? ''));
    }
}
