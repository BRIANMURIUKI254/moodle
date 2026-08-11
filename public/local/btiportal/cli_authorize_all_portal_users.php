<?php
/**
 * Authorise every active Moodle user for bti_portal_api (retroactive fix).
 * Also ensures local_btiportal_authorize_portal_user is on the service.
 * Run: docker exec moodle-app php /var/www/html/public/local/btiportal/cli_authorize_all_portal_users.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/service_auth_helper.php');

use local_btiportal\service_auth_helper;

$service = $DB->get_record('external_services', ['shortname' => service_auth_helper::PORTAL_SERVICE_SHORTNAME], '*', MUST_EXIST);
echo "service id={$service->id} shortname={$service->shortname} restrictedusers={$service->restrictedusers}\n";

$fn = 'local_btiportal_authorize_portal_user';
if (!$DB->record_exists('external_functions', ['name' => $fn])) {
    echo "ERROR: {$fn} not in external_functions — run Moodle upgrade first.\n";
    exit(1);
}
if (!$DB->record_exists('external_services_functions', ['externalserviceid' => $service->id, 'functionname' => $fn])) {
    $DB->insert_record('external_services_functions', (object)[
        'externalserviceid' => $service->id,
        'functionname' => $fn,
    ]);
    echo "Added {$fn} to service {$service->shortname}\n";
} else {
    echo "{$fn} already on service {$service->shortname}\n";
}
echo "\n";

$missing = $DB->get_records_sql(
    "SELECT u.id, u.username, u.email, u.firstname, u.lastname
       FROM {user} u
      WHERE u.deleted = 0 AND u.id > 1 AND u.suspended = 0
        AND NOT EXISTS (
            SELECT 1 FROM {external_services_users} esu
             WHERE esu.externalserviceid = ? AND esu.userid = u.id
        )
      ORDER BY u.id",
    [$service->id]
);

echo "=== Users to authorise (" . count($missing) . ") ===\n";
foreach ($missing as $u) {
    echo "  {$u->id}\t{$u->username}\t{$u->email}\t{$u->firstname} {$u->lastname}\n";
}

$created = 0;
foreach ($missing as $u) {
    $result = service_auth_helper::ensure_portal_authorised((int) $u->id);
    if ($result['created']) {
        $created++;
        echo "  authorised {$u->username} (id={$u->id})\n";
    }
}

echo "\nDone. newly_authorised={$created}\n";
