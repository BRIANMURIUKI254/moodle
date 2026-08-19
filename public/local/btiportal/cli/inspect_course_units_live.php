<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
global $DB;

cli_heading('course_unit rows');
$rows = $DB->get_records('local_btiportal_course_unit', null, 'id ASC');
foreach ($rows as $r) {
    cli_writeln(sprintf(
        'id=%d code=%s name=%s programme=%d curriculum=%d year=%d sem=%d courseid=%d status=%s',
        $r->id,
        $r->code,
        $r->name,
        $r->programmeid,
        $r->curriculumid,
        $r->yearofstudy,
        $r->semestersequence,
        $r->courseid,
        $r->status
    ));
}

cli_heading('curriculum_unit rows');
$assigns = $DB->get_records('local_btiportal_curriculum_unit', null, 'id ASC');
cli_writeln('count=' . count($assigns));
foreach ($assigns as $a) {
    cli_writeln(json_encode($a));
}

cli_heading('moodle courses (non-site)');
$courses = $DB->get_records_select('course', 'id > 1', [], 'id ASC', 'id,shortname,fullname', 0, 30);
foreach ($courses as $c) {
    cli_writeln("{$c->id} {$c->shortname} {$c->fullname}");
}
