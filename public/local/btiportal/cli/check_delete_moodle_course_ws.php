<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $DB;

$fn = 'local_btiportal_delete_moodle_course';
$exists = $DB->record_exists('external_functions', ['name' => $fn]);
echo "external_functions=" . ($exists ? '1' : '0') . PHP_EOL;

foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        echo "{$shortname}=MISSING\n";
        continue;
    }
    $linked = $DB->record_exists('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $fn,
    ]);
    echo "{$shortname} linked=" . ($linked ? '1' : '0') . PHP_EOL;
    if (!$linked) {
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ]);
        echo "{$shortname} LINKED_NOW\n";
    }
}
