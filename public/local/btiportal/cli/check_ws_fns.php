<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$fns = [
    'local_btiportal_get_events',
    'local_btiportal_publish_event',
    'local_btiportal_get_resources',
    'local_btiportal_publish_resource',
    'local_btiportal_set_resource_completion',
];
foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname]);
    cli_writeln("service {$shortname} id=" . ($service->id ?? 'MISSING'));
    if (!$service) continue;
    foreach ($fns as $fn) {
        $exists = $DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ]);
        $def = $DB->get_record('external_functions', ['name' => $fn], '*', IGNORE_MISSING);
        cli_writeln("  {$fn} on_service=" . ($exists ? 'yes' : 'NO') . ' classname=' . ($def->classname ?? 'MISSING'));
    }
}

$tokenrow = $DB->get_record_sql(
    "SELECT t.token, t.userid, u.username, s.shortname
       FROM {external_tokens} t
       JOIN {user} u ON u.id = t.userid
       JOIN {external_services} s ON s.id = t.externalserviceid
      WHERE u.username = ? AND s.shortname = ?
   ORDER BY t.timecreated DESC",
    ['portal_admin', 'bti_portal_api'],
    IGNORE_MISSING
);
cli_writeln('admin token userid=' . ($tokenrow->userid ?? 'n/a') . ' user=' . ($tokenrow->username ?? 'n/a'));
