<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;
echo 'ver=' . $DB->get_field('config_plugins', 'value', ['plugin' => 'local_btiportal', 'name' => 'version']) . PHP_EOL;
$mgr = $DB->get_manager();
foreach (['local_btiportal_school', 'local_btiportal_department', 'local_btiportal_curriculum_year', 'local_btiportal_curriculum_semester', 'local_btiportal_student_profile', 'local_btiportal_lecturer_dept', 'local_btiportal_staff_org'] as $t) {
    echo $t . '=' . (int)$mgr->table_exists($t) . PHP_EOL;
}
