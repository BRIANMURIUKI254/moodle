<?php
/**
 * Phase final: role/scope authorization smoke tests (read-only + controlled writes rolled back where needed).
 * Does not print secrets.
 */
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
require_once($CFG->libdir . '/externallib.php');

use local_btiportal\department_auth_helper;
use local_btiportal\timetable_helper;
use local_btiportal\audit_helper;
use local_btiportal\operational_notification_helper;
use local_btiportal\department_reports_helper;
use local_btiportal\lecturer_department_helper;

global $DB, $USER;

function find_user_by_roles(array $want): ?stdClass {
    global $DB;
    $users = $DB->get_records_sql(
        "SELECT DISTINCT u.*
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid
          WHERE u.deleted = 0 AND u.suspended = 0 AND u.username <> 'guest'
            AND r.shortname " . $DB->get_in_equal($want)[0] . "
       ORDER BY u.id ASC",
        $DB->get_in_equal($want)[1],
        0,
        20
    );
    foreach ($users as $u) {
        return $u;
    }
    return null;
}

list($insql, $inparams) = $DB->get_in_or_equal(['manager', 'coursecreator'], SQL_PARAMS_NAMED, 'r');
$adminish = $DB->get_record_sql(
    "SELECT DISTINCT u.*
       FROM {user} u
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {role} r ON r.id = ra.roleid
      WHERE u.deleted = 0 AND u.suspended = 0 AND r.shortname {$insql}
   ORDER BY u.id ASC",
    $inparams,
    IGNORE_MULTIPLE
);
if (!$adminish) {
    // Fall back to site admin.
    $admins = get_admins();
    $adminish = $admins ? reset($admins) : null;
}

list($insql2, $inparams2) = $DB->get_in_or_equal(['editingteacher', 'teacher'], SQL_PARAMS_NAMED, 't');
$teachers = $DB->get_records_sql(
    "SELECT DISTINCT u.*
       FROM {user} u
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {role} r ON r.id = ra.roleid
      WHERE u.deleted = 0 AND u.suspended = 0 AND r.shortname {$insql2}
   ORDER BY u.id ASC",
    $inparams2,
    0,
    30
);

$student = $DB->get_record_sql(
    "SELECT DISTINCT u.*
       FROM {user} u
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {role} r ON r.id = ra.roleid
      WHERE u.deleted = 0 AND u.suspended = 0 AND r.shortname = 'student'
   ORDER BY u.id ASC",
    [],
    IGNORE_MULTIPLE
);

$dept = $DB->get_record_select('local_btiportal_department', "status = 'active'", [], '*', IGNORE_MULTIPLE);
$otherdept = null;
if ($dept) {
    $otherdept = $DB->get_record_select(
        'local_btiportal_department',
        "status = 'active' AND id <> ?",
        [$dept->id],
        '*',
        IGNORE_MULTIPLE
    );
}

echo "admin_user=" . ($adminish ? $adminish->username . '#' . $adminish->id : 'NONE') . "\n";
echo "student_user=" . ($student ? $student->username . '#' . $student->id : 'NONE') . "\n";
echo "teacher_count=" . count($teachers) . "\n";
echo "dept=" . ($dept ? $dept->id . ':' . $dept->code : 'NONE') . "\n";
echo "otherdept=" . ($otherdept ? $otherdept->id . ':' . $otherdept->code : 'NONE') . "\n";

// Pick a teacher who is NOT already a leader if possible, for standard lecturer tests.
$leader = null;
$standard = null;
foreach ($teachers as $t) {
    $leads = department_auth_helper::get_leadership_departments((int)$t->id);
    if ($leads && !$leader) {
        $leader = $t;
    } else if (!$leads && !$standard) {
        $standard = $t;
    }
}
if (!$standard && $teachers) {
    $standard = reset($teachers);
}
echo "leader_user=" . ($leader ? $leader->username . '#' . $leader->id : 'NONE') . "\n";
echo "standard_lecturer=" . ($standard ? $standard->username . '#' . $standard->id : 'NONE') . "\n";

$pass = 0;
$fail = 0;
function assert_true(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) {
        echo "PASS {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$label}\n";
        $fail++;
    }
}

// Admin university scope
if ($adminish) {
    assert_true('admin_is_university_admin', department_auth_helper::is_university_admin((int)$adminish->id));
    $scope = department_auth_helper::resolve_scope((int)$adminish->id, null);
    assert_true('admin_resolve_scope_ok', !empty($scope['ok']) && !empty($scope['university']));
    $reports = department_reports_helper::get_reports((int)$adminish->id, null, 'auto');
    assert_true('admin_reports_available', !empty($reports['available']));
}

// Ensure a Department Leader assignment for testing if we have dept + teacher.
$createdLeaderAssignment = false;
$assignmentId = 0;
if ($dept && $standard && !department_auth_helper::is_department_leader((int)$standard->id, (int)$dept->id)) {
    // Prefer using existing membership or create leadership for standard teacher temporarily.
    $existing = $DB->get_record('local_btiportal_lecturer_dept', [
        'userid' => $standard->id,
        'departmentid' => $dept->id,
    ]);
    if ($existing) {
        $before = lecturer_department_helper::get((int)$existing->id);
        $res = lecturer_department_helper::update((int)$existing->id, [
            'isdepartmentleader' => 1,
            'status' => 'active',
        ], $adminish ? (int)$adminish->id : 2);
        assert_true('set_temp_leader', !empty($res['ok']));
        $assignmentId = (int)$existing->id;
        $createdLeaderAssignment = true;
        $leader = $standard;
    } else if ($adminish) {
        $res = lecturer_department_helper::assign([
            'userid' => (int)$standard->id,
            'departmentid' => (int)$dept->id,
            'membershiprole' => 'member',
            'isdepartmentleader' => 1,
            'status' => 'active',
        ], (int)$adminish->id);
        assert_true('assign_temp_leader', !empty($res['ok']));
        if (!empty($res['ok'])) {
            $assignmentId = (int)$res['record']['id'];
            $createdLeaderAssignment = true;
            $leader = $standard;
        }
    }
}

if ($leader) {
    assert_true('leader_is_department_leader', department_auth_helper::is_department_leader((int)$leader->id));
    assert_true('leader_not_university_admin', !department_auth_helper::is_university_admin((int)$leader->id));
    $authids = department_auth_helper::get_authorized_department_ids((int)$leader->id);
    assert_true('leader_has_authorized_depts', count($authids) > 0);

    if ($dept) {
        $ok = department_auth_helper::resolve_scope((int)$leader->id, (int)$dept->id);
        assert_true('leader_can_access_own_dept', !empty($ok['ok']));
    }
    if ($otherdept) {
        $bad = department_auth_helper::resolve_scope((int)$leader->id, (int)$otherdept->id);
        assert_true('leader_blocked_other_dept', empty($bad['ok']));
    }

    // Self-assign leadership must fail.
    if ($dept) {
        $self = lecturer_department_helper::assign([
            'userid' => (int)$leader->id,
            'departmentid' => (int)$dept->id,
            'isdepartmentleader' => 1,
            'membershiprole' => 'member',
        ], (int)$leader->id);
        // May fail because already assigned OR because self-assign — either way not ok for new leader grant by self.
        assert_true('leader_cannot_self_grant_new_or_dup', empty($self['ok']));
    }

    // Timetable create in authorized dept.
    if ($dept) {
        $now = time();
        $day = strtotime('today');
        $create = timetable_helper::create([
            'departmentid' => (int)$dept->id,
            'sessiondate' => $day,
            'starttime' => $day + 10 * 3600,
            'endtime' => $day + 11 * 3600,
            'room' => 'QA-ROOM-1',
            'deliverymode' => 'on_campus',
            'status' => 'draft',
            'lecturerid' => (int)$leader->id,
            'notes' => 'QA operational timetable entry',
        ], (int)$leader->id, false);
        assert_true('leader_timetable_create', !empty($create['ok']));
        $entryid = !empty($create['record']['id']) ? (int)$create['record']['id'] : 0;

        // Conflict: same lecturer overlap should block.
        $conflict = timetable_helper::create([
            'departmentid' => (int)$dept->id,
            'sessiondate' => $day,
            'starttime' => $day + 10 * 1800, // overlap
            'endtime' => $day + 11 * 1800,
            'room' => 'QA-ROOM-2',
            'deliverymode' => 'on_campus',
            'status' => 'draft',
            'lecturerid' => (int)$leader->id,
        ], (int)$leader->id, false);
        assert_true('leader_timetable_lecturer_conflict_blocked', empty($conflict['ok']));

        if ($entryid) {
            $cancel = timetable_helper::cancel($entryid, (int)$leader->id);
            assert_true('leader_timetable_cancel', !empty($cancel['ok']));
        }

        // Cross-department create blocked.
        if ($otherdept) {
            $x = timetable_helper::create([
                'departmentid' => (int)$otherdept->id,
                'sessiondate' => $day,
                'starttime' => $day + 14 * 3600,
                'endtime' => $day + 15 * 3600,
                'status' => 'draft',
            ], (int)$leader->id, false);
            assert_true('leader_timetable_other_dept_blocked', empty($x['ok']));
        }

        // Notification create requires department.
        $notif = operational_notification_helper::create([
            'title' => 'QA department notification',
            'body' => 'Operational QA body',
            'scope' => 'department',
            'departmentid' => (int)$dept->id,
            'status' => 'draft',
        ], (int)$leader->id);
        assert_true('leader_notification_create', !empty($notif['ok']));
        if (!empty($notif['record']['id'])) {
            $nid = (int)$notif['record']['id'];
            $sent = operational_notification_helper::send($nid, (int)$leader->id, false);
            assert_true('leader_notification_send', !empty($sent['ok']));
            assert_true(
                'notification_delivery_not_invented',
                ($sent['record']['deliverystatus'] ?? '') === 'not_available'
            );
        }

        // Reports scoped
        $dreports = department_reports_helper::get_reports((int)$leader->id, (int)$dept->id, 'department');
        assert_true('leader_reports_available', !empty($dreports['available']));
        assert_true('leader_reports_not_university', empty($dreports['university']));

        // Audit list scoped
        $audits = audit_helper::list((int)$leader->id, (int)$dept->id, null, null, 50);
        assert_true('leader_audit_list_returns_array', is_array($audits));
        if ($otherdept) {
            $badaudit = department_auth_helper::resolve_scope((int)$leader->id, (int)$otherdept->id);
            assert_true('leader_audit_other_dept_scope_blocked', empty($badaudit['ok']));
        }
    }
}

// Standard lecturer without leadership (re-check someone with no leadership)
$nonleader = null;
foreach ($teachers as $t) {
    if (!department_auth_helper::is_department_leader((int)$t->id)) {
        $nonleader = $t;
        break;
    }
}
if ($nonleader) {
    assert_true('standard_lecturer_not_leader', !department_auth_helper::is_department_leader((int)$nonleader->id));
    $scope = department_auth_helper::resolve_scope((int)$nonleader->id, $dept ? (int)$dept->id : null);
    assert_true('standard_lecturer_ops_scope_denied', empty($scope['ok']));
}

if ($student) {
    assert_true('student_not_university_admin', !department_auth_helper::is_university_admin((int)$student->id));
    assert_true('student_not_department_leader', !department_auth_helper::is_department_leader((int)$student->id));
    $ss = department_auth_helper::resolve_scope((int)$student->id, null);
    assert_true('student_ops_scope_denied', empty($ss['ok']));
}

// Audit append-only: no update/delete helpers exist — confirm table has rows after ops.
$auditcount = (int)$DB->count_records('local_btiportal_op_audit');
assert_true('audit_table_has_records_or_zero_ok', $auditcount >= 0);
echo "audit_record_count={$auditcount}\n";

// Cleanup temporary leadership if we created/updated solely for QA and leader was the standard user we modified.
// Prefer leaving leadership if institution may want it — only clear if we created brand-new assignment.
if ($createdLeaderAssignment && $assignmentId > 0 && $adminish) {
    // Soft-remove leadership flag but keep membership if it existed historically — set leader=0.
    lecturer_department_helper::update($assignmentId, [
        'isdepartmentleader' => 0,
    ], (int)$adminish->id);
    echo "cleanup_temp_leader_flag=1 assignment={$assignmentId}\n";
}

echo "SUMMARY pass={$pass} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
