<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

raise_memory_limit(MEMORY_HUGE);

$pluginver = $DB->get_field('config_plugins', 'value', [
    'plugin' => 'local_btiportal',
    'name' => 'version',
]);
cli_writeln('Installed local_btiportal version: ' . ($pluginver ?: 'unknown'));

$plugin = new stdClass();
include($CFG->dirroot . '/local/btiportal/version.php');
cli_writeln('Filesystem version: ' . ($plugin->version ?? '?') . ' release ' . ($plugin->release ?? '?'));

$seeded = 0;
if ($DB->get_manager()->table_exists('local_btiportal_progression_rule')
    && $DB->get_manager()->table_exists('local_btiportal_curriculum')) {
    $curricula = $DB->get_records('local_btiportal_curriculum', null, '', 'id, programmeid');
    foreach ($curricula as $curriculum) {
        foreach (['semester_progression', 'promotion'] as $actiontype) {
            $exists = $DB->record_exists('local_btiportal_progression_rule', [
                'curriculumid' => (int)$curriculum->id,
                'actiontype' => $actiontype,
                'status' => 'active',
            ]);
            if ($exists) {
                continue;
            }
            $now = time();
            $DB->insert_record('local_btiportal_progression_rule', (object)[
                'curriculumid' => (int)$curriculum->id,
                'programmeid' => (int)$curriculum->programmeid,
                'academicyearid' => 0,
                'curriculumyearid' => 0,
                'curriculumsemesterid' => 0,
                'actiontype' => $actiontype,
                'mincompletedunits' => 0,
                'maxfailedunits' => 999,
                'requiremanualapproval' => 0,
                'status' => 'active',
                'version' => 1,
                'notes' => 'Default rule seeded by cli/seed_defaults.php',
                'usermodified' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $seeded++;
        }
    }
}
cli_writeln("Progression rules seeded: {$seeded}");

$fixed = 0;
if ($DB->get_manager()->table_exists('local_btiportal_student_profile')) {
    $profiles = $DB->get_records_select(
        'local_btiportal_student_profile',
        '(curriculumyearid > 0 AND curriculumsemesterid = 0) OR (academicyearid > 0 AND semesterid = 0)'
    );
    foreach ($profiles as $profile) {
        \local_btiportal\student_delivery_plan_helper::resolve_placement_gaps((array)$profile, true);
        $fixed++;
    }
}
cli_writeln("Student profiles resolved: {$fixed}");

$fsver = (int)($plugin->version ?? 0);
$dbver = (int)$pluginver;
if ($fsver > $dbver) {
    set_config('version', $fsver, 'local_btiportal');
    cli_writeln("Updated config_plugins version to {$fsver}");
}

purge_all_caches();
cli_writeln('Caches purged. Done.');
