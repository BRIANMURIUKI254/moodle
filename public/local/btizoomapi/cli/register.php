<?php
/**
 * Register local_btizoomapi_ensure_zoom_link and refresh plugin metadata (no full site upgrade).
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->libdir . '/clilib.php');

external_update_descriptions('local_btizoomapi');
update_capabilities('local_btizoomapi');

$functions = [
    'local_btizoomapi_get_zoom_link',
    'local_btizoomapi_ensure_zoom_link',
];

foreach ($functions as $fn) {
    foreach (['bti_portal_api', 'btizoomapi_local'] as $shortname) {
        $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
        if (!$service) {
            echo "Service {$shortname}: missing\n";
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
            echo "Linked {$fn} -> {$shortname}\n";
        } else {
            echo "Already linked {$fn} -> {$shortname}\n";
        }
    }
}

set_config('version', 2026081100, 'local_btizoomapi');
purge_all_caches();
echo "Done. local_btizoomapi version=2026081100\n";
