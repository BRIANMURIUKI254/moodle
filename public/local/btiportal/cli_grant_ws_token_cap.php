<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/accesslib.php');

$syscontext = context_system::instance();
$caps = [
    'moodle/webservice:createtoken',
    'webservice/rest:use',
];

foreach (['user', 'editingteacher', 'student', 'manager'] as $shortname) {
    $role = $DB->get_record('role', ['shortname' => $shortname], '*', MUST_EXIST);
    foreach ($caps as $cap) {
        assign_capability($cap, CAP_ALLOW, $role->id, $syscontext->id, true);
        echo "Granted {$cap} to {$shortname}\n";
    }
}

if (function_exists('accesslib_clear_all_caches')) {
    accesslib_clear_all_caches(true);
}
echo "Caches cleared.\n";
