<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');

echo "COURSE UNITS vs MOODLE\n";
if (!$DB->get_manager()->table_exists('local_btiportal_course_unit')) {
    echo "course_unit table missing\n";
    exit(0);
}

$units = $DB->get_records('local_btiportal_course_unit', null, 'id ASC');
echo 'Units: ' . count($units) . "\n";
foreach ($units as $u) {
    $course = ((int)$u->courseid > 1)
        ? $DB->get_record('course', ['id' => (int)$u->courseid], 'id,shortname,fullname,visible')
        : false;
    $status = ((int)$u->courseid <= 1) ? 'not_connected' : ($course ? 'connected' : 'missing_moodle_course');
    echo sprintf(
        "  UNIT #%d %s %s status=%s courseid=%d moodle=%s mapping=%s\n",
        (int)$u->id,
        (string)$u->code,
        (string)$u->name,
        (string)$u->status,
        (int)$u->courseid,
        $course ? $course->shortname . ' vis=' . (int)$course->visible : '-',
        $status
    );
}

echo "MOODLE COURSES (id>1)\n";
$courses = $DB->get_records_select('course', 'id > 1', [], 'id ASC', 'id,shortname,fullname,visible,idnumber');
foreach ($courses as $c) {
    $linked = $DB->count_records_select('local_btiportal_course_unit', 'courseid = ?', [(int)$c->id]);
    echo sprintf(
        "  COURSE #%d %s vis=%d linked_units=%d\n",
        (int)$c->id,
        (string)$c->shortname,
        (int)$c->visible,
        $linked
    );
}
