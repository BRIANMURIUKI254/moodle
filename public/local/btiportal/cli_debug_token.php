<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/webservice/lib.php');

$caps = $DB->get_records_sql("SELECT name FROM {capabilities} WHERE component LIKE 'webservice%' OR name LIKE '%token%'");
foreach ($caps as $c) {
    echo $c->name . "\n";
}

echo "\n--- token check ---\n";
$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
foreach (['portal_instructor', 'portal_student'] as $username) {
    $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
    $can = external_can_create_token($service, $user);
    echo "{$username} external_can_create_token=" . ($can ? 'yes' : 'no') . "\n";
}
