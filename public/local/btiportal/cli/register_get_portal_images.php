<?php
/**
 * Register get_portal_images and refresh WS descriptions (no full plugin upgrade).
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/upgradelib.php');

external_update_descriptions('local_btiportal');

$fn = 'local_btiportal_get_portal_images';
foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        echo "missing service {$shortname}\n";
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
        echo "linked {$fn} to {$shortname}\n";
    } else {
        echo "already linked {$fn} to {$shortname}\n";
    }
}

set_config('version', 2026081101, 'local_btiportal');
purge_all_caches();
echo "done\n";
