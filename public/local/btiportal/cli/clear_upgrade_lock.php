<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

unset_config('upgraderunning');
purge_all_caches();
cli_writeln('Upgrade lock cleared.');
