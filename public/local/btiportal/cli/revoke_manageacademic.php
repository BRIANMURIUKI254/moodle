<?php
/**
 * Temporarily revoke/restore local/btiportal:manageacademic on Manager for Phase 3A gate testing.
 * Usage:
 *   php revoke_manageacademic.php revoke
 *   php revoke_manageacademic.php restore
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/accesslib.php');

list($options, $unrecognized) = cli_get_params(['help' => false], ['h' => 'help']);
$action = $unrecognized[0] ?? '';
if ($options['help'] || !in_array($action, ['revoke', 'restore'], true)) {
    cli_writeln("Usage: php revoke_manageacademic.php [revoke|restore]");
    exit($action ? 1 : 0);
}

$roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
$context = context_system::instance();
$cap = 'local/btiportal:manageacademic';

if ($action === 'revoke') {
    // CAP_PREVENT overrides CAP_ALLOW for the same role/context.
    assign_capability($cap, CAP_PREVENT, $roleid, $context->id, true);
    cli_writeln("REVOKED: assigned CAP_PREVENT for {$cap} on manager (roleid={$roleid})");
} else {
    // Ensure allow is present (plugin defaults may not re-apply to existing role rows).
    assign_capability($cap, CAP_ALLOW, $roleid, $context->id, true);
    cli_writeln("RESTORED: assigned CAP_ALLOW for {$cap} on manager (roleid={$roleid})");
    // Also restore coursecreator if that role exists.
    $creator = $DB->get_field('role', 'id', ['shortname' => 'coursecreator'], IGNORE_MISSING);
    if ($creator) {
        assign_capability($cap, CAP_ALLOW, (int)$creator, $context->id, true);
        cli_writeln("RESTORED: assigned CAP_ALLOW for {$cap} on coursecreator (roleid={$creator})");
    }
}

accesslib_clear_all_caches(true);
purge_all_caches();
cli_writeln('Caches purged.');
