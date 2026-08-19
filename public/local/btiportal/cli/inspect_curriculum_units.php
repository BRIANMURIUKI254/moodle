<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;

echo 'plugin=' . $DB->get_field('config_plugins', 'value', [
    'plugin' => 'local_btiportal',
    'name' => 'version',
]) . PHP_EOL;
echo 'curricula=' . $DB->count_records('local_btiportal_curriculum') . PHP_EOL;
echo 'units=' . $DB->count_records('local_btiportal_course_unit') . PHP_EOL;
echo 'years=' . $DB->count_records('local_btiportal_curriculum_year') . PHP_EOL;
echo 'semesters=' . $DB->count_records('local_btiportal_curriculum_semester') . PHP_EOL;

$currs = $DB->get_records('local_btiportal_curriculum', null, 'id ASC', 'id,code,name,version,programmeid,status');
foreach ($currs as $c) {
    $n = $DB->count_records('local_btiportal_course_unit', ['curriculumid' => (int)$c->id]);
    echo "CURR id={$c->id} code={$c->code} name={$c->name} ver={$c->version} units={$n}\n";
}

$units = $DB->get_records(
    'local_btiportal_course_unit',
    null,
    'id ASC',
    'id,code,name,curriculumid,yearofstudy,semestersequence,courseid,unittype,isrequired,status'
);
foreach ($units as $u) {
    echo "UNIT id={$u->id} code={$u->code} curr={$u->curriculumid} y{$u->yearofstudy}s{$u->semestersequence}"
        . " moodle={$u->courseid} type={$u->unittype} req={$u->isrequired} status={$u->status}\n";
}

$courses = $DB->get_records_select('course', 'id > 1', [], 'id ASC', 'id,shortname,fullname');
foreach ($courses as $co) {
    echo "MOODLE id={$co->id} short={$co->shortname}\n";
}
