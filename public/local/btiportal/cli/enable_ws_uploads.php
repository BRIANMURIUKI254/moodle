<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        cli_writeln("missing $shortname");
        continue;
    }
    $service->uploadfiles = 1;
    $DB->update_record('external_services', $service);
    cli_writeln("Enabled uploadfiles on $shortname");
}
cli_writeln('Done');
