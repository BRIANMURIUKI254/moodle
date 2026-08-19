<?php
/**
 * Register local_btiportal_ensure_my_teaching_access without a full site upgrade.
 */
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php');

global $DB;

$fn = 'local_btiportal_ensure_my_teaching_access';
external_update_descriptions('local_btiportal');

$exists = $DB->record_exists('external_functions', ['name' => $fn]);
if (!$exists) {
    cli_writeln("ERROR: {$fn} not in external_functions — copy plugin files first.");
    exit(1);
}

foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        cli_writeln("Skip service {$shortname} (not found)");
        continue;
    }
    if (!$DB->record_exists('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $fn,
    ])) {
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ]);
        cli_writeln("Registered {$fn} on {$shortname}");
    } else {
        cli_writeln("Already registered on {$shortname}");
    }
}

cli_writeln('Done.');
