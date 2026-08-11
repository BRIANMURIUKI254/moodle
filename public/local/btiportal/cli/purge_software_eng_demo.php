<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_btiportal\moodle_course_cleanup_helper;

cli_writeln('Scanning for SOFTWARE 01 / Software Eng demo Moodle courses…');

$courses = $DB->get_records_sql(
    "SELECT id, shortname, fullname
       FROM {course}
      WHERE id > 1
        AND (
              " . $DB->sql_like('shortname', ':s1', false) . "
           OR " . $DB->sql_like('shortname', ':s2', false) . "
           OR " . $DB->sql_like('fullname', ':f1', false) . "
           OR " . $DB->sql_like('fullname', ':f2', false) . "
        )
   ORDER BY id ASC",
    [
        's1' => '%SOFTWARE%01%',
        's2' => '%SOFTWARE 01%',
        'f1' => '%Software Eng%',
        'f2' => '%software eng%',
    ]
);

if (!$courses) {
    cli_writeln('No matching Moodle courses found.');
    exit(0);
}

foreach ($courses as $course) {
    cli_writeln("Deleting course #{$course->id}: {$course->shortname} — {$course->fullname}");
    try {
        $result = moodle_course_cleanup_helper::delete_moodle_course((int)$course->id);
        cli_writeln('  deleted=' . (!empty($result['deleted']) ? 'yes' : 'no')
            . ' purged_tables=' . count($result['purged'] ?? []));
    } catch (Throwable $e) {
        cli_writeln('  FAILED: ' . $e->getMessage());
    }
}

cli_writeln('Done.');
