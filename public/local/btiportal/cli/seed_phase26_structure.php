<?php
/**
 * Seed curriculum year/semester structure for Phase 26 validation.
 * Idempotent: skips existing year/semester numbers.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\curriculum_structure_helper;

$curriculumid = 1;
$userid = 16;
$adminid = 2;

cli_writeln('Seeding curriculum structure for curriculumid=' . $curriculumid);

$y1 = $DB->get_record('local_btiportal_curriculum_year', [
    'curriculumid' => $curriculumid,
    'yearnumber' => 1,
]);
if (!$y1) {
    $r = curriculum_structure_helper::create_year([
        'curriculumid' => $curriculumid,
        'yearnumber' => 1,
        'name' => 'Year 1',
        'status' => 'active',
    ], $adminid);
    if (empty($r['ok'])) {
        cli_error(json_encode($r['errors'] ?? []));
    }
    $y1id = (int)$r['record']['id'];
    cli_writeln('created year1 id=' . $y1id);
} else {
    $y1id = (int)$y1->id;
    cli_writeln('existing year1 id=' . $y1id);
}

$y2 = $DB->get_record('local_btiportal_curriculum_year', [
    'curriculumid' => $curriculumid,
    'yearnumber' => 2,
]);
if (!$y2) {
    $r = curriculum_structure_helper::create_year([
        'curriculumid' => $curriculumid,
        'yearnumber' => 2,
        'name' => 'Year 2',
        'status' => 'active',
    ], $adminid);
    if (empty($r['ok'])) {
        cli_error(json_encode($r['errors'] ?? []));
    }
    $y2id = (int)$r['record']['id'];
    cli_writeln('created year2 id=' . $y2id);
} else {
    $y2id = (int)$y2->id;
    cli_writeln('existing year2 id=' . $y2id);
}

$ensure_sem = static function (int $yearid, int $semnum) use ($DB, $adminid): int {
    $existing = $DB->get_record('local_btiportal_curriculum_semester', [
        'curriculumyearid' => $yearid,
        'semesternumber' => $semnum,
    ]);
    if ($existing) {
        cli_writeln("existing year={$yearid} sem={$semnum} id={$existing->id}");
        return (int)$existing->id;
    }
    $r = curriculum_structure_helper::create_semester([
        'curriculumyearid' => $yearid,
        'semesternumber' => $semnum,
        'name' => 'Semester ' . $semnum,
        'status' => 'active',
    ], $adminid);
    if (empty($r['ok'])) {
        cli_error(json_encode($r['errors'] ?? []));
    }
    $id = (int)$r['record']['id'];
    cli_writeln("created year={$yearid} sem={$semnum} id={$id}");
    return $id;
};

$s1 = $ensure_sem($y1id, 1);
$s2 = $ensure_sem($y1id, 2);
$s3 = $ensure_sem($y2id, 1);

$profile = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid]);
if (!$profile) {
    cli_error('Student profile not found for userid=' . $userid);
}

$profile->curriculumid = $curriculumid;
$profile->curriculumyearid = $y1id;
$profile->curriculumsemesterid = $s1;
$profile->timemodified = time();
$DB->update_record('local_btiportal_student_profile', $profile);

cli_writeln("student {$userid} placement: year={$y1id} semester={$s1} (y1s2={$s2} y2s1={$s3})");
cli_writeln('SEED_PHASE26_DONE');
