<?php
/**
 * Phase 26 end-to-end: activate units, plan, enrol, progress, verify identity.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\student_delivery_plan_helper;
use local_btiportal\academic_enrolment_helper;
use local_btiportal\student_academic_progression_helper;

$userid = 16;
$adminid = 2;

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$before = (object)['id' => $user->id, 'username' => $user->username, 'email' => $user->email];
cli_writeln("BEFORE id={$before->id} username={$before->username} email={$before->email}");

// Activate course units for curriculum 1.
foreach ($DB->get_records('local_btiportal_course_unit', ['curriculumid' => 1]) as $u) {
    $u->status = 'active';
    $u->timemodified = time();
    $DB->update_record('local_btiportal_course_unit', $u);
    cli_writeln("activated unit {$u->id} {$u->code} y={$u->yearofstudy} s={$u->semestersequence} courseid={$u->courseid}");
}

// Place on Year 1 Semester 1.
$y1 = $DB->get_record('local_btiportal_curriculum_year', ['curriculumid' => 1, 'yearnumber' => 1], '*', MUST_EXIST);
$s1 = $DB->get_record('local_btiportal_curriculum_semester', ['curriculumyearid' => $y1->id, 'semesternumber' => 1], '*', MUST_EXIST);
$s2 = $DB->get_record('local_btiportal_curriculum_semester', ['curriculumyearid' => $y1->id, 'semesternumber' => 2], '*', MUST_EXIST);
$profile = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid], '*', MUST_EXIST);
$profile->curriculumyearid = (int)$y1->id;
$profile->curriculumsemesterid = (int)$s1->id;
$profile->academicstatus = 'active';
$profile->timemodified = time();
$DB->update_record('local_btiportal_student_profile', $profile);
cli_writeln("placed on year={$y1->id} semester={$s1->id}");

// Force a real enrolment path: suspend active enrolment on mapped course if present.
$mappedcourseid = 4;
$ue = $DB->get_record_sql(
    "SELECT ue.id, ue.status
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE ue.userid = :userid AND e.courseid = :courseid
   ORDER BY ue.status ASC, ue.id DESC",
    ['userid' => $userid, 'courseid' => $mappedcourseid],
    IGNORE_MULTIPLE
);
if ($ue && (int)$ue->status === 0) {
    $DB->set_field('user_enrolments', 'status', 1, ['id' => (int)$ue->id]);
    cli_writeln('suspended_existing_enrolment id=' . (int)$ue->id);
}

$plan = student_delivery_plan_helper::get_plan($userid);
cli_writeln('plan_expected=' . (int)($plan['plan']['summary']['expected'] ?? 0));
cli_writeln('plan_not_enrolled=' . (int)($plan['plan']['summary']['not_enrolled'] ?? 0));
cli_writeln('plan_ended=' . (int)($plan['plan']['summary']['ended'] ?? 0));
cli_writeln('plan_enrolled=' . (int)($plan['plan']['summary']['enrolled'] ?? 0));
cli_writeln('plan_unmapped=' . (int)($plan['plan']['summary']['unmapped'] ?? 0));
cli_writeln('plan_canenrol=' . (!empty($plan['plan']['canenrol']) ? '1' : '0'));

$preview = academic_enrolment_helper::preview($userid, 'manual_reconciliation');
cli_writeln('preview_ok=' . (!empty($preview['ok']) ? '1' : '0'));
cli_writeln('toenrol=' . count($preview['preview']['toenrol'] ?? []));
cli_writeln('already=' . count($preview['preview']['alreadyenrolled'] ?? []));

$exec = academic_enrolment_helper::execute($userid, 'manual_reconciliation', [], $adminid);
cli_writeln('enrol_exec_ok=' . (!empty($exec['ok']) ? '1' : '0'));
if (!empty($exec['ok'])) {
    cli_writeln('enrolled_count=' . count($exec['result']['enrolled'] ?? []));
    cli_writeln('already_count=' . count($exec['result']['alreadyenrolled'] ?? []));
    cli_writeln('failed_count=' . count($exec['result']['failed'] ?? []));
    cli_writeln('op_id=' . (int)($exec['result']['operation']['id'] ?? 0));
    cli_writeln('op_status=' . (string)($exec['result']['operation']['status'] ?? ''));
} else {
    cli_writeln('enrol_exec_error=' . ($exec['errors'][0]['message'] ?? json_encode($exec)));
}

$plan2 = student_delivery_plan_helper::get_plan($userid);
cli_writeln('after_enrol_expected=' . (int)($plan2['plan']['summary']['expected'] ?? 0));
cli_writeln('after_enrol_enrolled=' . (int)($plan2['plan']['summary']['enrolled'] ?? 0));

// Progress to semester 2.
$prog = student_academic_progression_helper::preview_semester_progression($userid, [
    'academicyearid' => (int)$profile->academicyearid,
    'semesterid' => (int)$profile->semesterid,
]);
cli_writeln('sem_preview_ok=' . (!empty($prog['ok']) ? '1' : '0'));
if (!empty($prog['ok'])) {
    $target = $prog['preview']['target'];
    cli_writeln('target_year=' . ($target['curriculumyearid'] ?? '') . ' target_sem=' . ($target['curriculumsemesterid'] ?? ''));
    $done = student_academic_progression_helper::progress_semester($userid, [
        'academicyearid' => (int)$target['academicyearid'],
        'semesterid' => (int)$target['semesterid'],
        'reason' => 'Phase26 e2e semester progression',
    ], $adminid, false);
    cli_writeln('sem_progress_ok=' . (!empty($done['ok']) ? '1' : '0'));
    cli_writeln('history_action=' . ($done['result']['history']['actiontype'] ?? ''));
}

$after = $DB->get_record('user', ['id' => $userid], 'id,username,email', MUST_EXIST);
cli_writeln("AFTER id={$after->id} username={$after->username} email={$after->email}");
cli_writeln('same_userid=' . ((int)$after->id === (int)$before->id ? '1' : '0'));
cli_writeln('same_username=' . ($after->username === $before->username ? '1' : '0'));
cli_writeln('same_email=' . ($after->email === $before->email ? '1' : '0'));

$profile2 = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid], '*', MUST_EXIST);
cli_writeln("after_placement year={$profile2->curriculumyearid} sem={$profile2->curriculumsemesterid}");

$plan3 = student_delivery_plan_helper::get_plan($userid);
cli_writeln('post_progress_expected=' . (int)($plan3['plan']['summary']['expected'] ?? 0));

// Promotion preview/execute from Y1 (to Y2 first semester).
$prom = student_academic_progression_helper::preview_promotion($userid, [
    'academicyearid' => (int)$profile2->academicyearid,
    'semesterid' => (int)$profile2->semesterid,
]);
cli_writeln('promotion_preview_ok=' . (!empty($prom['ok']) ? '1' : '0'));
if (!empty($prom['ok'])) {
    cli_writeln('promo_target_year=' . ($prom['preview']['target']['curriculumyearid'] ?? ''));
    $promoexec = student_academic_progression_helper::promote($userid, [
        'academicyearid' => (int)$prom['preview']['target']['academicyearid'],
        'semesterid' => (int)$prom['preview']['target']['semesterid'],
        'reason' => 'Phase26 e2e promotion',
    ], $adminid, false);
    cli_writeln('promotion_ok=' . (!empty($promoexec['ok']) ? '1' : '0'));
    cli_writeln('promotion_action=' . ($promoexec['result']['history']['actiontype'] ?? ''));
    $afterpromo = $DB->get_record('user', ['id' => $userid], 'id,username,email', MUST_EXIST);
    cli_writeln('same_userid_after_promo=' . ((int)$afterpromo->id === (int)$before->id ? '1' : '0'));
    cli_writeln('same_username_after_promo=' . ($afterpromo->username === $before->username ? '1' : '0'));
    cli_writeln('same_email_after_promo=' . ($afterpromo->email === $before->email ? '1' : '0'));
} else {
    cli_writeln('promotion_note=' . ($prom['errors'][0]['message'] ?? ''));
}

cli_writeln('PHASE26_E2E_DONE');
