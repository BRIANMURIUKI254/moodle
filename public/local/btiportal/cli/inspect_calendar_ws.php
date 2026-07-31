<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$fn = $DB->get_record('external_functions', ['name' => 'local_btiportal_create_course_calendar_events']);
cli_writeln($fn ? 'fn ok class=' . $fn->classname : 'fn MISSING');

$svc = $DB->get_record('external_services', ['shortname' => 'bti_portal_api']);
$linked = $svc && $DB->record_exists('external_services_functions', [
    'externalserviceid' => $svc->id,
    'functionname' => 'local_btiportal_create_course_calendar_events',
]);
cli_writeln('on bti_portal_api=' . ($linked ? 'yes' : 'no'));

$instructor = $DB->get_record_sql(
    "SELECT id, username, firstname, lastname FROM {user} WHERE deleted=0 AND (username LIKE '%instructor%' OR firstname LIKE '%Portal%') ORDER BY id",
    [],
    IGNORE_MULTIPLE
);
if ($instructor) {
    cli_writeln("sample instructor id={$instructor->id} {$instructor->username}");
}

foreach ($DB->get_records('course', null, 'id ASC', 'id,shortname,fullname') as $c) {
    if ((int)$c->id <= 1) {
        continue;
    }
    cli_writeln("course {$c->id} {$c->shortname}");
}

$units = $DB->get_records('local_btiportal_unit');
cli_writeln('unit mappings=' . count($units));
foreach ($units as $u) {
    cli_writeln("  course={$u->courseid} section={$u->sectionid} teacher={$u->teacheruserid}");
}
