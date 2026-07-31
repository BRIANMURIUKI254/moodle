<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$rows = $DB->get_records_sql(
    "SELECT a.id, a.name, cm.id AS cmid, cm.section
       FROM {assign} a
       JOIN {course_modules} cm ON cm.instance = a.id
       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
      WHERE a.course = 2"
);
foreach ($rows as $r) {
    echo "assign={$r->id} cmid={$r->cmid} section={$r->section} name={$r->name}\n";
}
$secs = $DB->get_records('course_sections', ['course' => 2], 'section ASC');
foreach ($secs as $s) {
    echo "sec id={$s->id} num={$s->section} name=" . ($s->name ?: '(empty)') . "\n";
}
$maps = $DB->get_records('local_btiportal_unit', ['courseid' => 2]);
foreach ($maps as $m) {
    $u = $DB->get_record('user', ['id' => $m->teacheruserid], 'username');
    echo "map section={$m->sectionid} teacher={$u->username}\n";
}
echo "instructor2=";
$u2 = $DB->get_record('user', ['username' => 'portal_instructor2']);
echo $u2 ? "id={$u2->id}\n" : "missing\n";
