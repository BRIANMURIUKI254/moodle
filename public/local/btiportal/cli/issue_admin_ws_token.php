<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
require_once($CFG->dirroot . '/webservice/lib.php');
global $DB;

$admin = $DB->get_record('user', ['username' => 'admin', 'deleted' => 0], '*', MUST_EXIST);
$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);

// Prefer an existing valid token for admin + service.
$existing = $DB->get_record('external_tokens', [
    'userid' => $admin->id,
    'externalserviceid' => $service->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT,
]);
if ($existing && !empty($existing->token)) {
    echo "ADMIN_TOKEN={$existing->token}\n";
    exit(0);
}

$token = \core_external\util::generate_token(
    EXTERNAL_TOKEN_PERMANENT,
    \core_external\util::get_service_by_id($service->id),
    $admin->id,
    context_system::instance(),
    0,
    ''
);
echo "ADMIN_TOKEN={$token}\n";
