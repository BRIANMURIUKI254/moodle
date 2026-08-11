<?php
/**
 * Complete deletion of courses stuck with deletioninprogress=1 (e.g. when cron was down).
 */
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

global $DB;

$stuck = $DB->get_records_select('course', 'deletioninprogress = 1 AND id <> ?', [SITEID]);
if (!$stuck) {
    echo "NO_STUCK_COURSES\n";
    exit(0);
}

foreach ($stuck as $course) {
    echo "STUCK id={$course->id} shortname={$course->shortname}\n";
    $DB->set_field('course', 'deletioninprogress', 0, ['id' => $course->id]);
    $course = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
    delete_course($course, false);
    echo "DELETED id={$course->id}\n";
}

echo "DONE count=" . count($stuck) . "\n";
