<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_btizoomapi_install() {
    global $DB;

    // Link onto the portal token service used by the React app.
    $fn = 'local_btizoomapi_get_zoom_link';
    $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
    if ($service && !$DB->record_exists('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $fn,
    ])) {
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ]);
    }
}
