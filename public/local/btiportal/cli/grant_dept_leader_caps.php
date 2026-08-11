<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
require_once($CFG->libdir . '/accesslib.php');
global $DB;

$userid = 15;
$sysctx = context_system::instance();
$shortname = 'btideptleader';
$caps = [
    'local/btiportal:managetimetable',
    'local/btiportal:managedepartmentcalendar',
    'local/btiportal:managedepartmentnotifications',
    'local/btiportal:managedepartmentannouncements',
    'local/btiportal:viewdepartmentreports',
    'local/btiportal:viewdepartmentaudit',
];

$role = $DB->get_record('role', ['shortname' => $shortname]);
if (!$role) {
    $roleid = create_role('BTI Department Leader', $shortname, 'Operational department leadership for local_btiportal');
    $role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    echo "CREATED role {$shortname}#{$roleid}\n";
} else {
    echo "ROLE exists {$shortname}#{$role->id}\n";
}

foreach ($caps as $cap) {
    if (!$DB->record_exists('capabilities', ['name' => $cap])) {
        echo "MISSING_CAP {$cap}\n";
        continue;
    }
    assign_capability($cap, CAP_ALLOW, $role->id, $sysctx->id, true);
    echo "CAP {$cap}\n";
}

role_assign($role->id, $userid, $sysctx->id);
echo "ASSIGNED role to user #{$userid}\n";

$row = $DB->get_record_select(
    'local_btiportal_lecturer_dept',
    "userid = :u AND departmentid = 1 AND status = 'active'",
    ['u' => $userid]
);
if ($row) {
    $row->timestart = 0;
    $row->timeend = 0;
    $row->isdepartmentleader = 1;
    $DB->update_record('local_btiportal_lecturer_dept', $row);
    echo "leadership_dates_cleared\n";
}

accesslib_clear_all_caches(true);
echo "DONE\n";
