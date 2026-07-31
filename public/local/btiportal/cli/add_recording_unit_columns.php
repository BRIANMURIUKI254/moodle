<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$table = $CFG->prefix . 'local_btiportal_recording';
$cols = $DB->get_columns('local_btiportal_recording');
if (!isset($cols['sectionid'])) {
    $DB->execute("ALTER TABLE {$table} ADD COLUMN sectionid BIGINT NOT NULL DEFAULT 0 AFTER courseid");
    cli_writeln('Added sectionid');
}
if (!isset($cols['unitname'])) {
    $DB->execute("ALTER TABLE {$table} ADD COLUMN unitname VARCHAR(255) NOT NULL DEFAULT '' AFTER sectionid");
    cli_writeln('Added unitname');
}
cli_writeln('Done');
