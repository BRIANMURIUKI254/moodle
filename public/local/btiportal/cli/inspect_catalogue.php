<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;

echo 'schools=' . $DB->count_records('local_btiportal_school') . PHP_EOL;
echo 'depts=' . $DB->count_records('local_btiportal_department') . PHP_EOL;
echo 'active_depts=' . $DB->count_records_select('local_btiportal_department', "status = 'active'") . PHP_EOL;
echo 'lecturer_dept=' . $DB->count_records('local_btiportal_lecturer_dept') . PHP_EOL;

foreach ($DB->get_records('local_btiportal_school', null, 'id ASC', 'id,code,name,status', 0, 10) as $s) {
    echo "school {$s->id} {$s->code} status={$s->status}\n";
}
foreach ($DB->get_records('local_btiportal_department', null, 'id ASC', 'id,code,name,status,schoolid', 0, 20) as $d) {
    echo "dept {$d->id} {$d->code} school={$d->schoolid} status={$d->status}\n";
}
foreach ($DB->get_records('local_btiportal_lecturer_dept', null, 'id ASC', '*', 0, 20) as $r) {
    echo "ld {$r->id} user={$r->userid} dept={$r->departmentid} role={$r->membershiprole} leader=" .
        ($r->isdepartmentleader ?? 0) . " status={$r->status}\n";
}
