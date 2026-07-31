<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_btizoomapi_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026072100) {
        external_update_descriptions('local_btizoomapi');

        $fn = 'local_btizoomapi_get_zoom_link';
        foreach (['bti_portal_api', 'btizoomapi_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
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
            }
        }

        upgrade_plugin_savepoint(true, 2026072100, 'local', 'btizoomapi');
    }

    if ($oldversion < 2026072200) {
        // Refresh WS signature so optional cmid is registered.
        external_update_descriptions('local_btizoomapi');
        upgrade_plugin_savepoint(true, 2026072200, 'local', 'btizoomapi');
    }

    return true;
}
