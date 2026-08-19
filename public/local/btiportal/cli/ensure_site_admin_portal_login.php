<?php
/**
 * Ensure Moodle Site Administrators can sign in to the custom portal.
 *
 * Moodle core refuses to auto-create bti_portal_api tokens for site admins
 * via login/token.php (cannotcreatetoken). This script:
 *  - registers local_btiportal_get_current_user_context on bti_portal_api
 *  - adds each site admin to Authorised users when restrictedusers=1
 *  - creates a permanent token if none exists
 *
 * It does not print tokens or passwords.
 *
 * Run:
 *   docker exec moodle-app php /var/www/html/public/local/btiportal/cli/ensure_site_admin_portal_login.php
 */
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php');

use local_btiportal\service_auth_helper;

global $DB;

$fn = 'local_btiportal_get_current_user_context';
external_update_descriptions('local_btiportal');

if (!$DB->record_exists('external_functions', ['name' => $fn])) {
    cli_writeln("ERROR: {$fn} is not in external_functions — copy plugin files and upgrade first.");
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
        cli_writeln("Linked {$fn} to {$shortname}");
    } else {
        cli_writeln("{$fn} already on {$shortname}");
    }
}

$service = $DB->get_record(
    'external_services',
    ['shortname' => service_auth_helper::PORTAL_SERVICE_SHORTNAME],
    '*',
    IGNORE_MISSING
);
if (!$service) {
    cli_writeln('ERROR: bti_portal_api service was not found.');
    exit(1);
}

cli_writeln("service id={$service->id} restrictedusers={$service->restrictedusers}");

$results = service_auth_helper::ensure_site_admins_can_login();
if (!$results) {
    cli_writeln('No Moodle Site Administrators found.');
    exit(0);
}

foreach ($results as $row) {
    $parts = [
        "userid={$row['userid']}",
        "username={$row['username']}",
        'authorised=' . ($row['authorised'] ? 'yes' : 'no'),
        'token=' . ($row['token_created'] ? 'created' : ($row['token_existed'] ? 'exists' : 'missing')),
    ];
    if ($row['authorisation_created']) {
        $parts[] = 'authorised_users=added';
    }
    if ($row['error'] !== '') {
        $parts[] = 'error=' . $row['error'];
    }
    cli_writeln(implode(' ', $parts));
}

cli_writeln('Done. Site Administrators can now use the existing portal login (username + password → login/token.php).');
