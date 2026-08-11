<?php
/**
 * Remove empty Moodle "New section" placeholder topics from all courses.
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_btiportal\unit_helper;

cli_heading('Purge empty New section topics');

$sections = $DB->get_records_sql(
    "SELECT cs.*
       FROM {course_sections} cs
      WHERE cs.course > :siteid
        AND cs.section > 0
   ORDER BY cs.course ASC, cs.section ASC",
    ['siteid' => SITEID]
);

$removed = 0;
foreach ($sections as $section) {
    $name = trim((string)($section->name ?? ''));
    $isplaceholder = $name === ''
        || preg_match('/^new (section|topic)$/i', $name)
        || preg_match('/^(topic|section)\s*\d+$/i', $name);
    if (!$isplaceholder) {
        continue;
    }

    $modcount = (int)$DB->count_records_select(
        'course_modules',
        'course = ? AND section = ? AND deletioninprogress = 0',
        [(int)$section->course, (int)$section->id]
    );
    if ($modcount > 0) {
        cli_writeln("Skip #{$section->id} course {$section->course} (has {$modcount} modules)");
        continue;
    }

    try {
        unit_helper::delete_course_unit((int)$section->course, (int)$section->id);
        cli_writeln("Removed section #{$section->id} from course {$section->course} (" . ($name !== '' ? $name : 'unnamed') . ")");
        $removed++;
    } catch (Throwable $e) {
        cli_writeln("Failed section #{$section->id}: " . $e->getMessage());
    }
}

cli_writeln("Removed {$removed} placeholder section(s).");
purge_all_caches();
