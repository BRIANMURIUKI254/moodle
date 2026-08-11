<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;
$fns = [
    'local_btiportal_list_schools',
    'local_btiportal_list_departments',
    'local_btiportal_set_student_academic_profile',
    'local_btiportal_get_admin_academic_dashboard',
    'local_btiportal_list_lecturer_departments',
    'local_btiportal_assign_staff_org',
    'local_btiportal_list_academic_course_units',
    'local_btiportal_list_programmes',
];
foreach ($fns as $f) {
    echo $f . '=' . (int)$DB->record_exists('external_functions', ['name' => $f]) . PHP_EOL;
}
