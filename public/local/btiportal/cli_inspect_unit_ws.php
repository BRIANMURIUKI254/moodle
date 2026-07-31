<?php
/**
 * Inspect unit mapping table + ensure_unit_teacher web service registration.
 *
 * Run:
 *   docker exec moodle-app php /var/www/html/public/local/btiportal/cli_inspect_unit_ws.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

global $DB, $CFG;

$fn = 'local_btiportal_ensure_unit_teacher';
$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], 'id', IGNORE_MISSING);

$fnExists = $DB->record_exists('external_functions', ['name' => $fn]);
$inService = false;
if ($service) {
    $inService = $DB->record_exists('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $fn,
    ]);
}

$table = $CFG->prefix . 'local_btiportal_unit';
$tableExists = $DB->record_exists_sql(
    'SELECT 1
       FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name = ?',
    [$table]
);

echo 'table_exists=' . ($tableExists ? 'yes' : 'no') . PHP_EOL;
echo 'fn_exists=' . ($fnExists ? 'yes' : 'no') . PHP_EOL;
echo 'fn_on_bti_portal_api_service=' . ($inService ? 'yes' : 'no') . PHP_EOL;

