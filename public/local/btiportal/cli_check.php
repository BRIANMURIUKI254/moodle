<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once $CFG->libdir . '/upgradelib.php';

echo 'dirroot=' . $CFG->dirroot . "\n";
echo moodle_needs_upgrading() ? "NEEDS_UPGRADE\n" : "NO_UPGRADE\n";
$plugins = core_plugin_manager::instance()->get_plugins_of_type('local');
foreach ($plugins as $p) {
    echo $p->component . ' db=' . $p->versiondb . ' disk=' . $p->versiondisk . "\n";
}
