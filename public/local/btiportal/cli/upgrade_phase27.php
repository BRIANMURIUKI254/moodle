<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php');

$pluginman = \core_plugin_manager::instance();
$info = $pluginman->get_plugin_info('local_btiportal');
cli_writeln('disk_version=' . ($info ? $info->versiondisk : 'null'));
cli_writeln('db_version=' . ($info ? $info->versiondb : 'null'));
cli_writeln('version.php_release_check=' . get_config('local_btiportal', 'version'));

if ($info && (int)$info->versiondisk > (int)$info->versiondb) {
    cli_writeln('Upgrading local_btiportal...');
    upgrade_noncore(true);
    cli_writeln('upgrade_noncore_done');
} else {
    cli_writeln('No plugin version bump detected (disk <= db) or info missing');
}

cli_writeln('after_db_version=' . get_config('local_btiportal', 'version'));
cli_writeln('elective_group=' . ($DB->get_manager()->table_exists('local_btiportal_elective_group') ? '1' : '0'));
cli_writeln('FORCE_PLUGIN_UPGRADE_DONE');
