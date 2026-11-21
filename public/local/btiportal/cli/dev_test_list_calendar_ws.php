<?php
/**
 * Dev: call list_academic_years + list_semesters as a user with viewacademic (REST via wwwroot).
 */
define('CLI_SCRIPT', true);

require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

list($options,) = cli_get_params(['username' => 'fee.admin', 'password' => 'Test@Dev2026!'], []);

$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
$base = rtrim($CFG->wwwroot, '/');
$curl = new curl();

$raw = trim((string) $curl->post($base . '/login/token.php', [
    'username' => $options['username'],
    'password' => $options['password'],
    'service' => $service->shortname,
]));
$tok = json_decode($raw, true);
if (empty($tok['token'])) {
    cli_error('No token: ' . $raw);
}

function ws_post(string $base, string $token, string $fn, array $extra = []): string {
    $curl = new curl();
    $post = array_merge([
        'wstoken' => $token,
        'wsfunction' => $fn,
        'moodlewsrestformat' => 'json',
    ], $extra);
    return trim((string) $curl->post($base . '/webservice/rest/server.php', $post));
}

cli_writeln('=== local_btiportal_list_academic_years (status=active) ===');
cli_writeln(ws_post($base, $tok['token'], 'local_btiportal_list_academic_years', ['status' => 'active']));

cli_writeln('');
cli_writeln('=== local_btiportal_list_semesters (all) ===');
cli_writeln(ws_post($base, $tok['token'], 'local_btiportal_list_semesters', []));