<?php
/**
 * Phase 27 validation: electives, delivery compare, reconcile, eligibility.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\elective_helper;
use local_btiportal\academic_delivery_compare_helper;
use local_btiportal\enrolment_reconciliation_helper;
use local_btiportal\progression_eligibility_helper;
use local_btiportal\student_delivery_plan_helper;
use local_btiportal\academic_enrolment_helper;

cli_writeln('Phase 27 validation');
cli_writeln('plugin_version=' . get_config('local_btiportal', 'version'));

$user = $DB->get_record('user', ['username' => 'portal_student', 'deleted' => 0], '*', IGNORE_MISSING);
if (!$user) {
    cli_error('portal_student not found');
}
$userid = (int)$user->id;
$before = $DB->get_record('user', ['id' => $userid], 'id,username,email', MUST_EXIST);
cli_writeln("user id={$before->id} username={$before->username} email={$before->email}");

$profile = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid], '*', MUST_EXIST);

// Elective group create (idempotent by code).
$existing = $DB->get_record('local_btiportal_elective_group', [
    'curriculumid' => 1,
    'yearofstudy' => 1,
    'semestersequence' => 1,
    'code' => 'EG-A',
]);
if (!$existing) {
    $g = elective_helper::create_group([
        'curriculumid' => 1,
        'yearofstudy' => 1,
        'semestersequence' => 1,
        'code' => 'EG-A',
        'name' => 'Elective Group A',
        'minselect' => 1,
        'maxselect' => 1,
        'status' => 'active',
    ], 2);
    cli_writeln('elective_group_create=' . (!empty($g['ok']) ? '1' : '0'));
    if (!empty($g['ok'])) {
        $gid = (int)$g['record']['id'];
        // Attach an elective/optional unit if any, else unit 2.
        $unit = $DB->get_record_sql(
            "SELECT * FROM {local_btiportal_course_unit}
              WHERE curriculumid = 1 AND status = 'active'
           ORDER BY id ASC",
            [],
            IGNORE_MULTIPLE
        );
        if ($unit) {
            $add = elective_helper::add_unit_to_group($gid, (int)$unit->id, 2);
            cli_writeln('elective_unit_add=' . (!empty($add['ok']) ? '1' : '0'));
            $sel = elective_helper::save_student_selections(
                $userid,
                $gid,
                [(int)$unit->id],
                (int)$profile->academicyearid,
                (int)$profile->semesterid,
                2,
                'confirmed'
            );
            cli_writeln('elective_select_ok=' . (!empty($sel['ok']) ? '1' : '0'));
        }
    }
} else {
    cli_writeln('elective_group_existing=' . (int)$existing->id);
}

$plan = student_delivery_plan_helper::get_plan($userid);
cli_writeln('delivery_plan_ok=' . (!empty($plan['ok']) ? '1' : '0'));
if (!empty($plan['ok'])) {
    cli_writeln('plan_expected=' . (int)$plan['plan']['summary']['expected']);
    cli_writeln('plan_elective_groups=' . count($plan['plan']['electivegroups'] ?? []));
}

$placementpreview = student_delivery_plan_helper::preview_for_placement([
    'curriculumid' => (int)$profile->curriculumid,
    'curriculumyearid' => (int)$profile->curriculumyearid ?: 1,
    'curriculumsemesterid' => (int)$profile->curriculumsemesterid ?: 1,
    'programmeid' => (int)$profile->programmeid,
], $userid);
cli_writeln('placement_preview_ok=' . (!empty($placementpreview['ok']) ? '1' : '0'));

$compare = academic_delivery_compare_helper::compare($userid, [
    'schoolid' => (int)$profile->schoolid,
    'departmentid' => (int)$profile->departmentid,
    'programmeid' => (int)$profile->programmeid,
    'curriculumid' => (int)$profile->curriculumid,
    'curriculumyearid' => (int)($profile->curriculumyearid ?: 1),
    'curriculumsemesterid' => (int)($profile->curriculumsemesterid ?: 1),
    'academicyearid' => (int)$profile->academicyearid,
    'semesterid' => (int)$profile->semesterid,
], 'preserve_and_enrol_new');
cli_writeln('compare_ok=' . (!empty($compare['ok']) ? '1' : '0'));
if (!empty($compare['ok'])) {
    cli_writeln('compare_policy=' . $compare['comparison']['policy']);
    cli_writeln('compare_add=' . (int)$compare['comparison']['summary']['add']);
    cli_writeln('compare_retain=' . (int)$compare['comparison']['summary']['retain']);
}

$recon = enrolment_reconciliation_helper::preview($userid, 'manual_reconciliation');
cli_writeln('reconcile_preview_ok=' . (!empty($recon['ok']) ? '1' : '0'));

$elig = progression_eligibility_helper::assess($userid, 'semester_progression');
cli_writeln('eligibility_ok=' . (!empty($elig['ok']) ? '1' : '0'));
if (!empty($elig['ok'])) {
    cli_writeln('eligibility_state=' . $elig['eligibility']['state']);
    cli_writeln('eligibility_notice=' . $elig['eligibility']['notice']);
}

$after = $DB->get_record('user', ['id' => $userid], 'id,username,email', MUST_EXIST);
cli_writeln('same_userid=' . ((int)$after->id === (int)$before->id ? '1' : '0'));
cli_writeln('same_username=' . ($after->username === $before->username ? '1' : '0'));
cli_writeln('same_email=' . ($after->email === $before->email ? '1' : '0'));

cli_writeln('PHASE27_VALIDATION_DONE');
