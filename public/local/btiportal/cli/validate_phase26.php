<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\curriculum_delivery_helper;
use local_btiportal\student_delivery_plan_helper;
use local_btiportal\academic_enrolment_helper;
use local_btiportal\student_academic_progression_helper;
use local_btiportal\lecturer_teaching_helper;
use local_btiportal\student_profile_helper;
use local_btiportal\course_unit_teacher_helper;

cli_writeln('Phase 26 validation');
cli_writeln('plugin_version=' . get_config('local_btiportal', 'version'));

$teachertable = 'local_btiportal_course_unit_teacher';
$hasperiod = $DB->get_manager()->field_exists($teachertable, 'academicyearid');
cli_writeln('teacher_period_fields=' . ($hasperiod ? '1' : '0'));
cli_writeln('enrol_ops_table=' . ($DB->get_manager()->table_exists('local_btiportal_enrol_ops') ? '1' : '0'));

$curriculum = $DB->get_record_sql(
    "SELECT * FROM {local_btiportal_curriculum} ORDER BY id ASC",
    [],
    IGNORE_MULTIPLE
);
if ($curriculum) {
    $delivery = curriculum_delivery_helper::get_curriculum_delivery((int)$curriculum->id);
    cli_writeln('curriculum_delivery_ok=' . (!empty($delivery['ok']) ? '1' : '0'));
    if (!empty($delivery['ok'])) {
        cli_writeln('delivery_units=' . (int)$delivery['record']['totals']['courseunits']);
        cli_writeln('delivery_mapped=' . (int)$delivery['record']['totals']['mapped']);
        cli_writeln('delivery_unmapped=' . (int)$delivery['record']['totals']['unmapped']);
    }
}

$user = $DB->get_record('user', ['username' => 'portal_student', 'deleted' => 0], '*', IGNORE_MISSING);
if ($user) {
    $userid = (int)$user->id;
    $before = $DB->get_record('user', ['id' => $userid], 'id,username,email', MUST_EXIST);
    $plan = student_delivery_plan_helper::get_plan($userid);
    cli_writeln('delivery_plan_ok=' . (!empty($plan['ok']) ? '1' : '0'));
    if (!empty($plan['ok'])) {
        cli_writeln('plan_expected=' . (int)$plan['plan']['summary']['expected']);
        cli_writeln('plan_not_enrolled=' . (int)$plan['plan']['summary']['not_enrolled']);
        cli_writeln('plan_unmapped=' . (int)$plan['plan']['summary']['unmapped']);
    }

    $preview = academic_enrolment_helper::preview($userid, 'manual_reconciliation');
    cli_writeln('enrol_preview_ok=' . (!empty($preview['ok']) ? '1' : '0'));
    if (!empty($preview['ok'])) {
        cli_writeln('enrol_toenrol=' . count($preview['preview']['toenrol']));
        cli_writeln('enrol_skipped=' . count($preview['preview']['skipped']));
    }

    // Progression preview (may fail if placement incomplete — report honestly).
    $prog = student_academic_progression_helper::preview_semester_progression($userid, []);
    cli_writeln('semester_preview_ok=' . (!empty($prog['ok']) ? '1' : '0'));
    if (empty($prog['ok'])) {
        cli_writeln('semester_preview_error=' . ($prog['errors'][0]['message'] ?? ''));
    } else {
        $exec = student_academic_progression_helper::progress_semester($userid, [
            'academicyearid' => (int)$prog['preview']['target']['academicyearid'],
            'semesterid' => (int)$prog['preview']['target']['semesterid'],
            'reason' => 'Phase 26 validation progression',
        ], 2, false);
        cli_writeln('semester_progress_ok=' . (!empty($exec['ok']) ? '1' : '0'));
        if (!empty($exec['ok'])) {
            $after = $DB->get_record('user', ['id' => $userid], 'id,username,email', MUST_EXIST);
            cli_writeln('same_user_after_progression=' . ((int)$after->id === (int)$before->id ? '1' : '0'));
            cli_writeln('same_username_after_progression=' . ($after->username === $before->username ? '1' : '0'));
            cli_writeln('history_action=' . ($exec['result']['history']['actiontype'] ?? ''));
        } else {
            cli_writeln('semester_progress_error=' . ($exec['errors'][0]['message'] ?? ''));
        }
    }
}

$teacher = $DB->get_record_sql(
    "SELECT userid FROM {local_btiportal_course_unit_teacher} WHERE status = 'active' ORDER BY id ASC",
    [],
    IGNORE_MULTIPLE
);
if ($teacher) {
    $wl = lecturer_teaching_helper::get_workload((int)$teacher->userid, null, null, false);
    cli_writeln('workload_units=' . (int)$wl['activecourseunits']);
    cli_writeln('workload_legacy=' . (int)$wl['legacyunperiodedcount']);
}

cli_writeln('PHASE26_VALIDATION_DONE');
