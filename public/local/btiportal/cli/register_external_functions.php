<?php
/**
 * Register external function definitions from db/services.php into Moodle.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php'); // external_update_descriptions()

unset_config('upgraderunning');

external_update_descriptions('local_btiportal');

$fns = [
    'local_btiportal_publish_event',
    'local_btiportal_get_events',
    'local_btiportal_publish_resource',
    'local_btiportal_get_resources',
    'local_btiportal_set_resource_completion',
    'local_btiportal_get_certificates',
    'local_btiportal_ensure_certificate',
    'local_btiportal_publish_announcement',
    'local_btiportal_get_announcements',
    'local_btiportal_publish_recording',
    'local_btiportal_get_recordings',
    'local_btiportal_create_course_calendar_events',
];
foreach ($fns as $fn) {
    $def = $DB->get_record('external_functions', ['name' => $fn], '*', IGNORE_MISSING);
    cli_writeln($fn . ' => ' . ($def ? $def->classname . '::' . $def->methodname : 'MISSING'));
}

foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        continue;
    }
    foreach ($fns as $fn) {
        if (!$DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ])) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
            cli_writeln("Linked {$fn} to {$shortname}");
        }
    }
}

set_config('version', 2026072021, 'local_btiportal');
purge_all_caches();
cli_writeln('Done.');
