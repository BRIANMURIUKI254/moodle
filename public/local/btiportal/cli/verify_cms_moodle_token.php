<?php
/**
 * Verify the Moodle user behind a CMS/service token can call admissions WS.
 */
define('CLI_SCRIPT', true);

require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/accesslib.php');

list($options, $unrecognized) = cli_get_params(
    ['help' => false, 'username' => '', 'userid' => 0],
    ['h' => 'help', 'u' => 'username']
);

if ($options['help'] || $unrecognized) {
    echo "Usage: php verify_cms_moodle_token.php --username=cms.service\n";
    exit(0);
}

$userid = (int) $options['userid'];
if ($userid <= 0 && $options['username'] !== '') {
    $userid = (int) $DB->get_field('user', 'id', ['username' => $options['username'], 'deleted' => 0]);
}
if ($userid <= 0) {
    cli_error('Pass --username or --userid');
}

$ctx = context_system::instance();
$user = $DB->get_record('user', ['id' => $userid]);
cli_writeln('User: ' . $user->username . ' (id ' . $userid . ')');

$checks = [
    'local/btiportal:managestudentfees' => 'upsert_student, set_fees_paid',
    'local/btiportal:viewacademic' => 'list_programmes, list_academic_years, list_semesters',
];
foreach ($checks as $cap => $label) {
    $ok = has_capability($cap, $ctx, $userid);
    cli_writeln(($ok ? 'OK' : 'MISSING') . '  ' . $cap . '  (' . $label . ')');
}

$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
if (!$service) {
    cli_writeln('WARN: bti_portal_api service not found');
    exit(0);
}

$fns = [
    'local_btiportal_upsert_student',
    'local_btiportal_set_fees_paid',
    'local_btiportal_list_programmes',
    'local_btiportal_list_academic_years',
    'local_btiportal_list_semesters',
];
cli_writeln('');
cli_writeln('Functions on bti_portal_api:');
foreach ($fns as $fn) {
    $on = $DB->record_exists('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $fn,
    ]);
    cli_writeln(($on ? 'OK' : 'MISSING') . '  ' . $fn);
}

$token = $DB->get_field('external_tokens', 'token', [
    'userid' => $userid,
    'externalserviceid' => $service->id,
], 'id DESC');
cli_writeln('');
cli_writeln('Permanent token for user on bti_portal_api: ' . ($token ? 'yes' : 'NO (use login/token.php or issue token)'));