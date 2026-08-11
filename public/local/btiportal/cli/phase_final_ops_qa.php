<?php
/**
 * Seed minimal active school + two departments for operational QA.
 * Idempotent: reuses QA_SCHOOL / QA_DEPT_A / QA_DEPT_B if present.
 */
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\school_helper;
use local_btiportal\department_helper;
use local_btiportal\lecturer_department_helper;
use local_btiportal\department_auth_helper;
use local_btiportal\timetable_helper;
use local_btiportal\operational_notification_helper;
use local_btiportal\department_reports_helper;
use local_btiportal\audit_helper;

global $DB;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? 'PASS' : 'FAIL') . " {$label}\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

$admin = null;
$admins = get_admins();
if ($admins) {
    $admin = reset($admins);
}
if (!$admin) {
    echo "FATAL no site admin\n";
    exit(2);
}
$actor = (int)$admin->id;

$school = $DB->get_record('local_btiportal_school', ['code' => 'QA_SCHOOL']);
if (!$school) {
    $res = school_helper::create([
        'code' => 'QA_SCHOOL',
        'name' => 'QA Operational School',
        'description' => 'Seeded for final operational QA',
        'status' => 'active',
    ], $actor);
    check('seed_school', !empty($res['ok']));
    $schoolid = (int)($res['record']['id'] ?? 0);
} else {
    if ($school->status !== 'active') {
        school_helper::update((int)$school->id, ['status' => 'active'], $actor);
    }
    $schoolid = (int)$school->id;
    check('reuse_school', $schoolid > 0);
}

function ensure_dept(int $schoolid, string $code, string $name, int $actor): int {
    global $DB;
    $row = $DB->get_record('local_btiportal_department', ['code' => $code]);
    if ($row) {
        if ($row->status !== 'active') {
            department_helper::update((int)$row->id, ['status' => 'active'], $actor);
        }
        return (int)$row->id;
    }
    $res = department_helper::create([
        'schoolid' => $schoolid,
        'code' => $code,
        'name' => $name,
        'description' => 'Seeded for final operational QA',
        'status' => 'active',
    ], $actor);
    if (empty($res['ok'])) {
        echo 'ERR create dept ' . $code . ' ' . json_encode($res['errors'] ?? []) . PHP_EOL;
        return 0;
    }
    return (int)$res['record']['id'];
}

$deptA = ensure_dept($schoolid, 'QA_DEPT_A', 'QA Department A', $actor);
$deptB = ensure_dept($schoolid, 'QA_DEPT_B', 'QA Department B', $actor);
check('seed_dept_a', $deptA > 0);
check('seed_dept_b', $deptB > 0 && $deptB !== $deptA);

$instructor = $DB->get_record('user', ['username' => 'portal_instructor', 'deleted' => 0]);
if (!$instructor) {
    echo "FATAL portal_instructor missing\n";
    exit(2);
}

$existing = $DB->get_record('local_btiportal_lecturer_dept', [
    'userid' => $instructor->id,
    'departmentid' => $deptA,
]);
if ($existing) {
    $upd = lecturer_department_helper::update((int)$existing->id, [
        'isdepartmentleader' => 1,
        'status' => 'active',
        'membershiprole' => 'head',
        'isprimary' => 1,
    ], $actor);
    check('leader_assign_update', !empty($upd['ok']));
    $assignid = (int)$existing->id;
} else {
    $asg = lecturer_department_helper::assign([
        'userid' => (int)$instructor->id,
        'departmentid' => $deptA,
        'membershiprole' => 'head',
        'isdepartmentleader' => 1,
        'isprimary' => 1,
        'status' => 'active',
    ], $actor);
    check('leader_assign_create', !empty($asg['ok']));
    $assignid = (int)($asg['record']['id'] ?? 0);
}

// Self-grant must fail
$self = lecturer_department_helper::update($assignid, [
    'isdepartmentleader' => 1,
], (int)$instructor->id);
check('leader_self_grant_blocked', empty($self['ok']));

check('leader_active_on_a', department_auth_helper::is_department_leader((int)$instructor->id, $deptA));
check('leader_not_on_b', !department_auth_helper::is_department_leader((int)$instructor->id, $deptB));
$bad = department_auth_helper::resolve_scope((int)$instructor->id, $deptB);
check('leader_scope_b_denied', empty($bad['ok']));
$ok = department_auth_helper::resolve_scope((int)$instructor->id, $deptA);
check('leader_scope_a_ok', !empty($ok['ok']));

$day = strtotime('today');
$create = timetable_helper::create([
    'departmentid' => $deptA,
    'sessiondate' => $day,
    'starttime' => $day + 9 * 3600,
    'endtime' => $day + 10 * 3600,
    'room' => 'QA-101',
    'deliverymode' => 'on_campus',
    'status' => 'published',
    'lecturerid' => (int)$instructor->id,
    'notes' => 'Final QA entry',
], (int)$instructor->id, false);
check('timetable_create_dept_a', !empty($create['ok']));
$entryid = (int)($create['record']['id'] ?? 0);

$conflict = timetable_helper::create([
    'departmentid' => $deptA,
    'sessiondate' => $day,
    'starttime' => $day + 9 * 3600 + 1800,
    'endtime' => $day + 10 * 3600 + 1800,
    'room' => 'QA-102',
    'status' => 'draft',
    'lecturerid' => (int)$instructor->id,
], (int)$instructor->id, false);
check('timetable_lecturer_conflict_blocked', empty($conflict['ok']));

$roomclash = timetable_helper::create([
    'departmentid' => $deptA,
    'sessiondate' => $day,
    'starttime' => $day + 9 * 3600 + 900,
    'endtime' => $day + 10 * 3600,
    'room' => 'QA-101',
    'status' => 'draft',
    'lecturerid' => 0,
], (int)$instructor->id, false);
// Room overlap is WARNING — without override should fail
check('timetable_room_warning_requires_override', empty($roomclash['ok']));

$cross = timetable_helper::create([
    'departmentid' => $deptB,
    'sessiondate' => $day,
    'starttime' => $day + 13 * 3600,
    'endtime' => $day + 14 * 3600,
    'status' => 'draft',
], (int)$instructor->id, false);
check('timetable_cross_dept_blocked', empty($cross['ok']));

$listA = timetable_helper::list((int)$instructor->id, ['departmentid' => $deptA]);
$listB = timetable_helper::list((int)$instructor->id, ['departmentid' => $deptB]);
check('timetable_list_a_ok', is_array($listA));
check('timetable_list_b_empty_or_denied', count($listB) === 0);

if ($entryid) {
    $upd = timetable_helper::update($entryid, ['notes' => 'Final QA entry updated'], (int)$instructor->id, false);
    check('timetable_update', !empty($upd['ok']));
    $cancel = timetable_helper::cancel($entryid, (int)$instructor->id);
    check('timetable_cancel', !empty($cancel['ok']));
}

$notif = operational_notification_helper::create([
    'title' => 'QA Ops Notification',
    'body' => 'Department scoped QA',
    'scope' => 'department',
    'departmentid' => $deptA,
    'status' => 'draft',
], (int)$instructor->id);
check('notification_create', !empty($notif['ok']));
$nid = (int)($notif['record']['id'] ?? 0);
if ($nid) {
    $send = operational_notification_helper::send($nid, (int)$instructor->id, false);
    check('notification_send', !empty($send['ok']));
    check('notification_delivery_not_available', ($send['record']['deliverystatus'] ?? '') === 'not_available');
    // Cancel already-sent should fail
    $cancelSent = operational_notification_helper::cancel($nid, (int)$instructor->id);
    check('notification_cancel_sent_blocked', empty($cancelSent['ok']));
}

$badNotif = operational_notification_helper::create([
    'title' => 'Cross dept',
    'body' => 'Should fail',
    'scope' => 'department',
    'departmentid' => $deptB,
], (int)$instructor->id);
check('notification_cross_dept_blocked', empty($badNotif['ok']));

$allUsers = operational_notification_helper::create([
    'title' => 'All users attempt',
    'body' => 'Should fail for leader',
    'scope' => 'all_users',
    'departmentid' => $deptA,
], (int)$instructor->id);
check('notification_all_users_blocked_for_leader', empty($allUsers['ok']));

$reports = department_reports_helper::get_reports((int)$instructor->id, $deptA, 'department');
check('leader_reports_available', !empty($reports['available']));
check('leader_reports_not_university', empty($reports['university']));
check('export_not_configured', empty($reports['exportconfigured']));

$adminReports = department_reports_helper::get_reports($actor, null, 'auto');
check('admin_reports_university', !empty($adminReports['available']) && !empty($adminReports['university']));

$audits = audit_helper::list((int)$instructor->id, $deptA, null, null, 100);
check('leader_audit_has_ops_rows', count($audits) > 0);
$crossAuditScope = department_auth_helper::resolve_scope((int)$instructor->id, $deptB);
check('leader_cannot_resolve_other_dept_for_audit', empty($crossAuditScope['ok']));

$adminAudits = audit_helper::list($actor, null, null, null, 100);
check('admin_audit_university_list', is_array($adminAudits) && count($adminAudits) > 0);

// Student / non-leader isolation
$student = $DB->get_record('user', ['username' => 'portal_student', 'deleted' => 0]);
if ($student) {
    $ss = department_auth_helper::resolve_scope((int)$student->id, $deptA);
    check('student_ops_denied', empty($ss['ok']));
}

echo "deptA={$deptA} deptB={$deptB} instructor={$instructor->id} assign={$assignid}\n";
echo "audit_count=" . $DB->count_records('local_btiportal_op_audit') . "\n";
echo "SUMMARY pass={$pass} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
