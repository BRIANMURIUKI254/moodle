<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\curriculum_unit_helper;
use local_btiportal\curriculum_delivery_helper;
use local_btiportal\student_unit_pathway_helper;
use local_btiportal\lecturer_teaching_helper;

global $DB;

echo "=== VERSIONS ===\n";
echo 'installed=' . $DB->get_field('config_plugins', 'value', [
    'plugin' => 'local_btiportal',
    'name' => 'version',
]) . PHP_EOL;
$v = file_get_contents('/var/www/html/public/local/btiportal/version.php');
preg_match('/\$plugin->version\s*=\s*(\d+)/', $v, $m);
echo 'runtime_file=' . ($m[1] ?? '?') . PHP_EOL;
preg_match("/\\\$plugin->release\\s*=\\s*'([^']+)'/", $v, $m2);
echo 'runtime_release=' . ($m2[1] ?? '?') . PHP_EOL;
echo 'table_curriculum_unit=' . (int)$DB->get_manager()->table_exists('local_btiportal_curriculum_unit') . PHP_EOL;

echo "\n=== CURRICULA ===\n";
$currs = $DB->get_records('local_btiportal_curriculum', null, 'id ASC', 'id,code,name,version,programmeid,status');
foreach ($currs as $c) {
    $n = $DB->count_records('local_btiportal_curriculum_unit', ['curriculumid' => (int)$c->id]);
    echo "CURR id={$c->id} code={$c->code} name={$c->name} ver={$c->version} assignments={$n}\n";
}

echo "\n=== COURSE UNITS ===\n";
$units = $DB->get_records('local_btiportal_course_unit', null, 'id ASC');
foreach ($units as $u) {
    echo "UNIT id={$u->id} code={$u->code} name={$u->name} home_curr={$u->curriculumid}"
        . " moodle={$u->courseid} y{$u->yearofstudy}s{$u->semestersequence}\n";
}

echo "\n=== ASSIGNMENTS ===\n";
$asg = $DB->get_records('local_btiportal_curriculum_unit', null, 'curriculumid ASC, yearofstudy ASC, semestersequence ASC, sortorder ASC');
foreach ($asg as $a) {
    echo "ASG id={$a->id} curr={$a->curriculumid} unit={$a->courseunitid} y{$a->yearofstudy}s{$a->semestersequence} sort={$a->sortorder}\n";
}

echo "\n=== MOODLE COURSES ===\n";
$courses = $DB->get_records_select('course', 'id > 1', [], 'id ASC', 'id,shortname,fullname,idnumber');
foreach ($courses as $co) {
    echo "MOODLE id={$co->id} short={$co->shortname} idnumber={$co->idnumber}\n";
}

echo "\n=== PRESERVE CHECK ===\n";
$int001 = $DB->get_record('local_btiportal_course_unit', ['code' => 'INT001']);
$mat101 = $DB->get_record('local_btiportal_course_unit', ['code' => 'MAT101']);
echo 'INT001_courseid=' . ($int001->courseid ?? 0) . PHP_EOL;
echo 'MAT101_courseid=' . ($mat101->courseid ?? 0) . PHP_EOL;
echo 'ELE001_exists=' . (int)$DB->record_exists('course', ['id' => 7, 'shortname' => 'ELE001']) . PHP_EOL;
echo 'INT001_moodle8=' . (int)$DB->record_exists('course', ['id' => 8, 'shortname' => 'INT001']) . PHP_EOL;
echo 'MAT101_moodle9=' . (int)$DB->record_exists('course', ['id' => 9, 'shortname' => 'MAT101']) . PHP_EOL;

echo "\n=== STUDENT PROFILES ===\n";
if ($DB->get_manager()->table_exists('local_btiportal_student_profile')) {
    $profiles = $DB->get_records('local_btiportal_student_profile', null, 'id ASC', 'id,userid,programmeid,curriculumid,curriculumyearid,curriculumsemesterid,status', 0, 20);
    foreach ($profiles as $p) {
        echo "STUDENT userid={$p->userid} curr={$p->curriculumid} yearid={$p->curriculumyearid} semid={$p->curriculumsemesterid}\n";
        $path = student_unit_pathway_helper::get_pathway((int)$p->userid);
        echo '  current=' . count($path['current'] ?? []) . ' studied=' . count($path['studied'] ?? []) . ' upcoming=' . count($path['upcoming'] ?? []) . PHP_EOL;
        $all = array_merge($path['current'] ?? [], $path['studied'] ?? [], $path['upcoming'] ?? []);
        foreach ($all as $item) {
            echo "  UNIT {$item['code']} y{$item['yearofstudy']}s{$item['semestersequence']} moodle={$item['moodlecourseid']}\n";
        }
    }
}

echo "\n=== LECTURER TEACHING ===\n";
if ($DB->get_manager()->table_exists('local_btiportal_course_unit_teacher')) {
    $teachers = $DB->get_records('local_btiportal_course_unit_teacher', ['status' => 'active'], 'id ASC', '*', 0, 20);
    echo 'active_assignments=' . count($teachers) . PHP_EOL;
    foreach ($teachers as $t) {
        echo "TEACH userid={$t->userid} unit={$t->courseunitid} role={$t->teachingrole}\n";
        $units = lecturer_teaching_helper::list_course_units((int)$t->userid, 'active');
        echo '  listed_units=' . count($units) . PHP_EOL;
        foreach ($units as $lu) {
            echo "  LUNIT {$lu['code']} moodle={$lu['courseid']}\n";
        }
        $moodle = lecturer_teaching_helper::list_moodle_teaching_courses((int)$t->userid);
        echo '  moodle_teaching=' . count($moodle) . PHP_EOL;
    }
}

echo "\n=== WS FUNCTIONS ===\n";
foreach ([
    'local_btiportal_list_curriculum_unit_assignments',
    'local_btiportal_save_curriculum_unit_assignments',
    'local_btiportal_preview_academic_course_unit_moodle',
    'local_btiportal_sync_academic_course_unit_moodle',
    'local_btiportal_get_my_student_workspace',
    'local_btiportal_get_my_lecturer_workspace',
] as $fn) {
    $exists = $DB->record_exists('external_functions', ['name' => $fn]);
    echo "$fn registered=" . (int)$exists . PHP_EOL;
}
