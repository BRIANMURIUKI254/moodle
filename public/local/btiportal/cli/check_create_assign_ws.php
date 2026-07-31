<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
$fn = $DB->get_record('external_functions', ['name' => 'local_btiportal_create_document_assignment']);
echo $fn ? "FN_OK id={$fn->id}\n" : "FN_MISSING\n";
$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
$link = $DB->record_exists('external_services_functions', [
    'externalserviceid' => $service->id,
    'functionname' => 'local_btiportal_create_document_assignment',
]);
echo $link ? "SERVICE_OK\n" : "SERVICE_MISSING\n";
echo "version=" . get_config('local_btiportal', 'version') . "\n";
