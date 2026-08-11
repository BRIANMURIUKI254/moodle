<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB, $CFG;

$installed = $DB->get_field('config_plugins', 'value', [
    'plugin' => 'local_btiportal',
    'name' => 'version',
]);
echo "installed_version={$installed}\n";

$tables = [
    'local_btiportal_student_acad_hist',
    'local_btiportal_elective_group',
    'local_btiportal_elective_group_unit',
    'local_btiportal_student_elective_sel',
    'local_btiportal_progression_rule',
    'local_btiportal_enrol_ops',
    'local_btiportal_timetable',
    'local_btiportal_notification',
    'local_btiportal_op_audit',
    'local_btiportal_lecturer_dept',
    'local_btiportal_school',
    'local_btiportal_department',
    'local_btiportal_programme',
    'local_btiportal_event',
    'local_btiportal_announcement',
];

foreach ($tables as $t) {
    $full = $CFG->prefix . $t;
    $exists = $DB->record_exists_sql(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$full]
    );
    echo ($exists ? 'OK' : 'MISSING') . " {$t}\n";
}

$cols = ['isdepartmentleader', 'timestart', 'timeend', 'membershiprole', 'isprimary', 'status'];
$lt = $CFG->prefix . 'local_btiportal_lecturer_dept';
foreach ($cols as $c) {
    $exists = $DB->record_exists_sql(
        'SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$lt, $c]
    );
    echo ($exists ? 'OK' : 'MISSING') . " lecturer_dept.{$c}\n";
}

// Capabilities
$caps = [
    'local/btiportal:viewacademic',
    'local/btiportal:manageacademic',
    'local/btiportal:managetimetable',
    'local/btiportal:managedepartmentcalendar',
    'local/btiportal:managedepartmentnotifications',
    'local/btiportal:managedepartmentannouncements',
    'local/btiportal:viewdepartmentreports',
    'local/btiportal:viewdepartmentaudit',
];
foreach ($caps as $cap) {
    $exists = $DB->record_exists('capabilities', ['name' => $cap]);
    echo ($exists ? 'OK' : 'MISSING') . " cap {$cap}\n";
}

// Web services
$fns = [
    'local_btiportal_list_timetable_entries',
    'local_btiportal_create_timetable_entry',
    'local_btiportal_update_timetable_entry',
    'local_btiportal_cancel_timetable_entry',
    'local_btiportal_list_operational_audit',
    'local_btiportal_get_operational_reports',
    'local_btiportal_list_operational_notifications',
    'local_btiportal_create_operational_notification',
    'local_btiportal_send_operational_notification',
    'local_btiportal_cancel_operational_notification',
    'local_btiportal_get_current_user_context',
    'local_btiportal_list_lecturer_departments',
    'local_btiportal_assign_lecturer_department',
    'local_btiportal_update_lecturer_department',
];
foreach ($fns as $fn) {
    $exists = $DB->record_exists('external_functions', ['name' => $fn]);
    echo ($exists ? 'OK' : 'MISSING') . " fn {$fn}\n";
}

foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname]);
    if (!$service) {
        echo "MISSING service {$shortname}\n";
        continue;
    }
    echo "OK service {$shortname} id={$service->id}\n";
    foreach ([
        'local_btiportal_list_timetable_entries',
        'local_btiportal_get_operational_reports',
        'local_btiportal_list_operational_audit',
        'local_btiportal_list_operational_notifications',
        'local_btiportal_create_timetable_entry',
    ] as $fn) {
        $mapped = $DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ]);
        echo ($mapped ? 'OK' : 'MISSING') . " {$shortname}::{$fn}\n";
    }
}

echo "DONE\n";
