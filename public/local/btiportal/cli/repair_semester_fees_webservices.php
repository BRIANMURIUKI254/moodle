<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/btiportal/classes/webservice_register_helper.php');

cli_heading('Repair semester-fees web service functions');

$result = \local_btiportal\webservice_register_helper::ensure_portal_functions();

cli_writeln('Functions present in external_functions: ' . (int)$result['registered']);
cli_writeln('New service links inserted: ' . (int)$result['linked']);

if (!empty($result['missing'])) {
    cli_writeln('Still missing after external_update_descriptions (deploy plugin code first):');
    foreach ($result['missing'] as $fn) {
        cli_writeln('  - ' . $fn);
    }
    exit(1);
}

purge_all_caches();
cli_writeln('Purged caches. Admin /admin/fees should work now.');
exit(0);