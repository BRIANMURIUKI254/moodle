<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
$ctx = context_system::instance()->id;
$rows = $DB->get_records('role_capabilities', [
    'roleid' => $roleid,
    'capability' => 'local/btiportal:manageacademic',
    'contextid' => $ctx,
]);
cli_writeln('COUNT=' . count($rows));
foreach ($rows as $r) {
    cli_writeln('permission=' . $r->permission);
}

// Plugin default for manager from access.php after restore should allow via role definition.
$capinfo = get_capability_info('local/btiportal:manageacademic');
cli_writeln('CAP_DEFINED=' . ($capinfo ? '1' : '0'));
