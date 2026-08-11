<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\lecturer_teaching_helper;
use local_btiportal\student_academic_history_helper;
use local_btiportal\student_lifecycle_helper;
use local_btiportal\student_profile_helper;
use local_btiportal\enrolment_transition_helper;
use local_btiportal\course_unit_teacher_helper;

cli_writeln('Phase 25 validation');

$table = student_academic_history_helper::TABLE;
cli_writeln('history_table_exists=' . ($DB->get_manager()->table_exists($table) ? '1' : '0'));
cli_writeln('plugin_version=' . get_config('local_btiportal', 'version'));

$programme = $DB->get_record_sql(
    "SELECT * FROM {local_btiportal_programme} ORDER BY id ASC",
    [],
    IGNORE_MULTIPLE
);
$curriculum = $programme
    ? $DB->get_record('local_btiportal_curriculum', ['programmeid' => (int)$programme->id], '*', IGNORE_MULTIPLE)
    : false;

$user = $DB->get_record('user', ['username' => 'portal_student', 'deleted' => 0], '*', IGNORE_MISSING);
if (!$user) {
    $user = $DB->get_record_sql(
        "SELECT * FROM {user} WHERE deleted = 0 AND id > 1 AND username <> 'guest' ORDER BY id ASC",
        [],
        IGNORE_MULTIPLE
    );
}

if (!$user || !$programme || !$curriculum) {
    cli_writeln('SKIP: missing Moodle user or programme/curriculum for lifecycle round-trip');
    exit(0);
}

$userid = (int)$user->id;
$userbefore = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,email', MUST_EXIST);
cli_writeln("test_userid={$userbefore->id} username={$userbefore->username}");

$existing = student_profile_helper::get_by_userid($userid);
$createdtemp = false;
if (!$existing) {
    $created = student_profile_helper::create([
        'userid' => $userid,
        'schoolid' => 0,
        'departmentid' => (int)($programme->departmentid ?? 0),
        'programmeid' => (int)$programme->id,
        'curriculumid' => (int)$curriculum->id,
        'curriculumyearid' => 0,
        'curriculumsemesterid' => 0,
        'academicyearid' => 0,
        'semesterid' => 0,
        'admissiondate' => time(),
        'admissionstatus' => 'active',
        'academicstatus' => 'active',
        'status' => 'active',
    ], 2);
    if (empty($created['ok'])) {
        cli_writeln('PROFILE_CREATE_FAILED ' . json_encode($created['errors'] ?? []));
        exit(1);
    }
    $existing = $created['record'];
    $createdtemp = true;
    cli_writeln('temp_profile_created=1');
} else {
    // Ensure active for the round-trip.
    if ((string)$existing['academicstatus'] !== 'active') {
        student_profile_helper::update((int)$existing['id'], [
            'academicstatus' => 'active',
            'admissionstatus' => 'active',
        ], 2);
        $existing = student_profile_helper::get_by_userid($userid);
    }
}

$preview = enrolment_transition_helper::preview($userid);
cli_writeln('enrolment_preview_count=' . count($preview['enrolments']));

$year = $DB->get_record_sql(
    "SELECT * FROM {local_btiportal_academic_year} ORDER BY id ASC",
    [],
    IGNORE_MULTIPLE
);
$sem = $year
    ? $DB->get_record_sql(
        "SELECT * FROM {local_btiportal_semester} WHERE academicyearid = ? ORDER BY id ASC",
        [(int)$year->id],
        IGNORE_MULTIPLE
    )
    : false;

$defer = student_lifecycle_helper::defer([
    'userid' => $userid,
    'effectivedate' => time(),
    'expectedreturndate' => time() + WEEKSECS,
    'reason' => 'Phase 25 validation deferral',
    'notes' => 'Automated validation',
    'enrolmentpolicy' => 'preserve',
], 2);

if (empty($defer['ok'])) {
    cli_writeln('DEFER_FAILED ' . json_encode($defer['errors'] ?? []));
    exit(1);
}

$userafterdefer = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,email', MUST_EXIST);
cli_writeln('same_user_after_defer=' . ((int)$userafterdefer->id === (int)$userbefore->id ? '1' : '0'));
cli_writeln('same_username_after_defer=' . ($userafterdefer->username === $userbefore->username ? '1' : '0'));
cli_writeln('same_email_after_defer=' . ($userafterdefer->email === $userbefore->email ? '1' : '0'));
cli_writeln('status_after_defer=' . $defer['profile']['academicstatus']);
cli_writeln('history_action_defer=' . $defer['history']['actiontype']);

$returninput = [
    'userid' => $userid,
    'returndate' => time(),
    'reason' => 'Phase 25 validation return',
    'enrolmentpolicy' => 'preserve',
];
if ($year && $sem) {
    $returninput['academicyearid'] = (int)$year->id;
    $returninput['semesterid'] = (int)$sem->id;
} else {
    // Without calendar rows, temporarily patch profile then return with synthetic year ids blocked —
    // create ephemeral calendar if missing is too heavy; skip return and restore manually.
    cli_writeln('calendar_missing=1');
    student_profile_helper::update((int)$defer['profile']['id'], [
        'academicstatus' => 'active',
        'admissionstatus' => 'active',
    ], 2);
    // Still record that deferral history exists.
    $history = student_academic_history_helper::list_for_user($userid);
    cli_writeln('history_count=' . count($history));
    cli_writeln('PHASE25_VALIDATION_PARTIAL_NO_CALENDAR');
    exit(0);
}

$ret = student_lifecycle_helper::return_from_deferral($returninput, 2);
if (empty($ret['ok'])) {
    cli_writeln('RETURN_FAILED ' . json_encode($ret['errors'] ?? []));
    student_profile_helper::update((int)$defer['profile']['id'], [
        'academicstatus' => 'active',
        'admissionstatus' => 'active',
    ], 2);
    exit(1);
}

$userafter = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,email', MUST_EXIST);
cli_writeln('same_user_after_return=' . ((int)$userafter->id === (int)$userbefore->id ? '1' : '0'));
cli_writeln('status_after_return=' . $ret['profile']['academicstatus']);

// Withdraw + readmit round-trip.
$withdraw = student_lifecycle_helper::withdraw([
    'userid' => $userid,
    'effectivedate' => time(),
    'reason' => 'Phase 25 validation withdrawal',
    'enrolmentpolicy' => 'preserve',
], 2);
if (empty($withdraw['ok'])) {
    cli_writeln('WITHDRAW_FAILED ' . json_encode($withdraw['errors'] ?? []));
    exit(1);
}
cli_writeln('same_user_after_withdraw=' . (
    (int)$DB->get_field('user', 'id', ['id' => $userid]) === $userid ? '1' : '0'
));

$readmit = student_lifecycle_helper::readmit([
    'userid' => $userid,
    'effectivedate' => time(),
    'academicyearid' => (int)$year->id,
    'semesterid' => (int)$sem->id,
    'reason' => 'Phase 25 validation readmission',
    'enrolmentpolicy' => 'preserve',
], 2);
if (empty($readmit['ok'])) {
    cli_writeln('READMIT_FAILED ' . json_encode($readmit['errors'] ?? []));
    exit(1);
}
cli_writeln('same_user_after_readmit=' . (
    (int)$DB->get_field('user', 'id', ['id' => $userid]) === $userid ? '1' : '0'
));
cli_writeln('status_after_readmit=' . $readmit['profile']['academicstatus']);

$history = student_academic_history_helper::list_for_user($userid);
cli_writeln('history_count=' . count($history));
cli_writeln('history_newest=' . ($history[0]['actiontype'] ?? ''));
cli_writeln('history_ordered_newest_first=' . (
    count($history) < 2 || (int)$history[0]['id'] >= (int)$history[1]['id'] ? '1' : '0'
));

// Lecturer workload live path.
$lecturerid = 0;
$unitteacher = $DB->get_record_sql(
    "SELECT userid FROM {local_btiportal_course_unit_teacher} WHERE status = 'active' ORDER BY id ASC",
    [],
    IGNORE_MULTIPLE
);
if ($unitteacher) {
    $lecturerid = (int)$unitteacher->userid;
} else {
    $instr = $DB->get_record('user', ['username' => 'portal_instructor', 'deleted' => 0], 'id', IGNORE_MISSING);
    $lecturerid = $instr ? (int)$instr->id : 0;
}
if ($lecturerid > 0) {
    $wl = lecturer_teaching_helper::get_workload($lecturerid);
    cli_writeln('lecturer_workload_userid=' . $wl['userid']);
    cli_writeln('lecturer_active_units=' . $wl['activecourseunits']);
    cli_writeln('lecturer_moodle_courses=' . $wl['activemoodlecourses']);
    $units = lecturer_teaching_helper::list_course_units($lecturerid, 'active');
    cli_writeln('lecturer_listed_units=' . count($units));
}

cli_writeln('created_temp_profile=' . ($createdtemp ? '1' : '0'));
cli_writeln('PHASE25_VALIDATION_OK');
