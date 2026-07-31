<?php
/**
 * Inspect bti_portal_api authorised users vs all accounts.
 * Run: docker exec moodle-app php /var/www/html/public/local/btiportal/cli_inspect_auth.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
echo "service id={$service->id} shortname={$service->shortname} restrictedusers={$service->restrictedusers}\n\n";

$token = $DB->get_record('external_tokens', ['token' => 'e84f71c33dd5fe203a7f0480cc162a35'], '*', IGNORE_MISSING);
if ($token) {
    $tu = $DB->get_record('user', ['id' => $token->userid]);
    echo "VITE token userid={$token->userid} username={$tu->username} externalserviceid={$token->externalserviceid}\n";
    $ts = $DB->get_record('external_services', ['id' => $token->externalserviceid]);
    echo "token service shortname={$ts->shortname}\n\n";
} else {
    echo "VITE token not found in external_tokens\n\n";
}

$auth = $DB->get_records_sql(
    "SELECT u.id, u.username, u.email, u.firstname, u.lastname
       FROM {external_services_users} esu
       JOIN {user} u ON u.id = esu.userid
      WHERE esu.externalserviceid = ?
      ORDER BY u.id",
    [$service->id]
);
echo "=== Authorised ({$service->shortname}) ===\n";
foreach ($auth as $u) {
    echo "  {$u->id}\t{$u->username}\t{$u->email}\t{$u->firstname} {$u->lastname}\n";
}

$missing = $DB->get_records_sql(
    "SELECT u.id, u.username, u.email, u.firstname, u.lastname, u.timecreated
       FROM {user} u
      WHERE u.deleted = 0 AND u.id > 1 AND u.suspended = 0
        AND NOT EXISTS (
            SELECT 1 FROM {external_services_users} esu
             WHERE esu.externalserviceid = ? AND esu.userid = u.id
        )
      ORDER BY u.id",
    [$service->id]
);
echo "\n=== NOT authorised (broken for portal login) ===\n";
foreach ($missing as $u) {
    echo "  {$u->id}\t{$u->username}\t{$u->email}\t{$u->firstname} {$u->lastname}\n";
}
echo "\nAuthorised count=" . count($auth) . " Missing count=" . count($missing) . "\n";
