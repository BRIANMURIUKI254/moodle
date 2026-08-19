<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $DB, $CFG;

echo "===== student_profile =====\n";
foreach ($DB->get_records('local_btiportal_student_profile') as $p) {
    echo json_encode($p) . PHP_EOL;
}

echo "\n===== programmes =====\n";
foreach ($DB->get_records('local_btiportal_programme') as $p) {
    echo "id={$p->id} code={$p->code} name={$p->name} status={$p->status}\n";
}

echo "\n===== curricula =====\n";
foreach ($DB->get_records('local_btiportal_curriculum') as $p) {
    echo "id={$p->id} name={$p->name} programmeid={$p->programmeid} status={$p->status}\n";
}

echo "\n===== schools =====\n";
foreach ($DB->get_records('local_btiportal_school') as $p) {
    echo "id={$p->id} code={$p->code} name={$p->name}\n";
}

echo "\n===== departments =====\n";
foreach ($DB->get_records('local_btiportal_department') as $p) {
    echo "id={$p->id} code={$p->code} name={$p->name} schoolid={$p->schoolid}\n";
}

echo "\n===== course units =====\n";
foreach ($DB->get_records('local_btiportal_course_unit') as $p) {
    echo "id={$p->id} code={$p->code} name={$p->name} courseid={$p->courseid} curriculumid=" . ($p->curriculumid ?? '') . "\n";
}

echo "\n===== curriculum_unit =====\n";
foreach ($DB->get_records('local_btiportal_curriculum_unit') as $p) {
    echo "id={$p->id} curriculumid={$p->curriculumid} courseunitid={$p->courseunitid} year={$p->yearofstudy} sem={$p->semestersequence}\n";
}

echo "\n===== academic years =====\n";
foreach ($DB->get_records('local_btiportal_academic_year') as $p) {
    echo "id={$p->id} name={$p->name} status={$p->status}\n";
}

echo "\n===== semesters =====\n";
foreach ($DB->get_records('local_btiportal_semester') as $p) {
    echo "id={$p->id} name={$p->name} academicyearid={$p->academicyearid}\n";
}

echo "\n===== course_unit_teacher =====\n";
foreach ($DB->get_records('local_btiportal_course_unit_teacher') as $p) {
    echo "id={$p->id} courseunitid={$p->courseunitid} userid={$p->userid} role=" . ($p->role ?? '') . "\n";
}

echo "\n===== lecturer_dept =====\n";
foreach ($DB->get_records('local_btiportal_lecturer_dept') as $p) {
    echo json_encode($p) . PHP_EOL;
}

echo "\n===== timetable =====\n";
foreach ($DB->get_records('local_btiportal_timetable') as $p) {
    echo json_encode($p) . PHP_EOL;
}

echo "\n===== assignment_meta =====\n";
foreach ($DB->get_records('local_btiportal_assignment_meta') as $p) {
    echo json_encode($p) . PHP_EOL;
}

echo "\n===== local_btiportal_unit =====\n";
foreach ($DB->get_records('local_btiportal_unit') as $p) {
    echo "id={$p->id} courseid={$p->courseid} sectionid=" . ($p->sectionid ?? '') . " name=" . ($p->name ?? '') . "\n";
}

echo "\n===== categories =====\n";
foreach ($DB->get_records('course_categories') as $p) {
    echo "id={$p->id} name={$p->name} parent={$p->parent} coursecount={$p->coursecount}\n";
}

echo "\n===== zoom =====\n";
if ($DB->get_manager()->table_exists('zoom')) {
    foreach ($DB->get_records('zoom') as $p) {
        echo "id={$p->id} course={$p->course} name={$p->name}\n";
    }
}

echo "\n===== messages sample =====\n";
foreach ($DB->get_records('messages', null, 'id DESC', '*', 0, 5) as $p) {
    echo "id={$p->id} useridfrom={$p->useridfrom} conversationid={$p->conversationid} subject=" . ($p->subject ?? '') . "\n";
}

echo "\n===== notifications sample =====\n";
foreach ($DB->get_records('notifications', null, 'id DESC', 'id,useridfrom,useridto,subject,timecreated', 0, 10) as $p) {
    echo "id={$p->id} from={$p->useridfrom} to={$p->useridto} subject={$p->subject}\n";
}

echo "\n===== tokens for non-system users =====\n";
$tokens = $DB->get_records_sql(
    "SELECT t.id, t.userid, u.username, t.externalserviceid
       FROM {external_tokens} t
       JOIN {user} u ON u.id = t.userid
      WHERE t.userid NOT IN (1,2,3)"
);
echo "NONSYSTEM_TOKENS=" . count($tokens) . "\n";
foreach ($tokens as $t) {
    echo "tokenid={$t->id} user={$t->userid} {$t->username}\n";
}

echo "\n===== role assignments for test users =====\n";
$ras = $DB->get_records_sql(
    "SELECT ra.id, ra.userid, r.shortname, ctx.contextlevel, ctx.instanceid
       FROM {role_assignments} ra
       JOIN {role} r ON r.id = ra.roleid
       JOIN {context} ctx ON ctx.id = ra.contextid
      WHERE ra.userid NOT IN (1,2,3)
      ORDER BY ra.userid"
);
foreach ($ras as $ra) {
    echo "userid={$ra->userid} role={$ra->shortname} level={$ra->contextlevel} instance={$ra->instanceid}\n";
}

echo "\n===== files for courses 7-11 =====\n";
$files = $DB->get_records_sql(
    "SELECT id, contextid, component, filearea, filename, filesize
       FROM {files}
      WHERE filename <> '.' AND filename <> '..'
        AND (contextid IN (
            SELECT id FROM {context} WHERE contextlevel = 50 AND instanceid IN (7,8,9,10,11)
        ) OR userid IN (14,23,29,31,32,33))
      ORDER BY id"
);
echo "FILE_ROWS=" . count($files) . "\n";
$bytes = 0;
foreach ($files as $f) {
    $bytes += (int)$f->filesize;
    echo "id={$f->id} ctx={$f->contextid} {$f->component}/{$f->filearea} {$f->filename} size={$f->filesize}\n";
}
echo "FILE_BYTES={$bytes}\n";

echo "\n===== dataroot =====\n";
echo "CFG_dataroot={$CFG->dataroot}\n";
