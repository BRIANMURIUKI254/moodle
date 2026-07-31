<?php
/**
 * One-shot: register local_btizoomapi_get_zoom_link onto bti_portal_api.
 * Run: docker exec moodle-app php /var/www/html/public/local/btizoomapi/cli/register.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php');

external_update_descriptions('local_btizoomapi');

$fn = 'local_btizoomapi_get_zoom_link';
foreach (['bti_portal_api', 'btizoomapi_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        cli_writeln("Service {$shortname} missing — skip");
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
        cli_writeln("Linked {$fn} to {$shortname}");
    } else {
        cli_writeln("Already linked {$fn} to {$shortname}");
    }
}

purge_all_caches();
cli_writeln('Done.');
