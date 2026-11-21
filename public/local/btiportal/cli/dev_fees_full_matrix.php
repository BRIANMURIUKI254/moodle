<?php
/**
 * Dev: payment auth, cap checks, core WS leak, gate matrix, callback --show.
 */
define('CLI_SCRIPT', true);

require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/accesslib.php');

use local_btiportal\fees_gate_registry;

$pass = 'Test@Dev2026!';
$prevGate = (int)get_config('local_btiportal', 'feesgate_enabled');
set_config('feesgate_enabled', 1, 'local_btiportal');
register_shutdown_function(function () use ($prevGate) {
    set_config('feesgate_enabled', $prevGate, 'local_btiportal');
});
cli_writeln('feesgate_enabled=1 for this test run (restored on exit)');

function dev_token(string $username, string $password): string {
    global $DB, $CFG;
    $serviceid = (int)$DB->get_field('external_services', 'id', ['shortname' => 'bti_portal_api']);
    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if (!$user) {
        return '';
    }
    $t = $DB->get_field('external_tokens', 'token', ['userid' => $user->id, 'externalserviceid' => $serviceid], 'id DESC');
    if ($t) {
        return $t;
    }
    $curl = new curl();
    $raw = trim((string)$curl->post(rtrim($CFG->wwwroot, '/') . '/login/token.php', [
        'username' => $username,
        'password' => $password,
        'service' => 'bti_portal_api',
    ]));
    $j = json_decode($raw, true);
    return is_array($j) ? (string)($j['token'] ?? '') : '';
}

function ws(string $token, string $fn, array $extra = []): string {
    global $CFG;
    $curl = new curl();
    $post = array_merge([
        'wstoken' => $token,
        'wsfunction' => $fn,
        'moodlewsrestformat' => 'json',
    ], $extra);
    return trim((string)$curl->post(rtrim($CFG->wwwroot, '/') . '/webservice/rest/server.php', $post));
}

cli_writeln('=== configure_admissions_fees_callback --show ===');
passthru('php ' . escapeshellarg(__DIR__ . '/configure_admissions_fees_callback.php') . ' --show');

cli_writeln('');
cli_writeln('=== managestudentfees (fee.* test users) ===');
$ctx = context_system::instance();
foreach (['fee.unpaid', 'fee.instructor', 'fee.admin', 'fee.paid'] as $u) {
    $id = (int)$DB->get_field('user', 'id', ['username' => $u]);
    if ($id <= 0) {
        continue;
    }
    $cap = has_capability('local/btiportal:managestudentfees', $ctx, $id) ? '1' : '0';
    cli_writeln("$u id=$id managestudentfees=$cap");
}
$roles = $DB->get_records_sql(
    "SELECT DISTINCT r.shortname FROM {role} r
      JOIN {role_capabilities} rc ON rc.roleid = r.id
     WHERE rc.capability = ? AND rc.permission = ? AND rc.contextid = ?",
    ['local/btiportal:managestudentfees', CAP_ALLOW, $ctx->id]
);
cli_writeln('role_defs: ' . implode(', ', array_keys($roles)));

cli_writeln('');
cli_writeln('=== payment WS (instructor/admin) ===');
$attempts = (int)$DB->count_records('local_btiportal_fees_payment_attempt');
foreach (['fee.instructor', 'fee.admin'] as $u) {
    $t = dev_token($u, $pass);
    $b = (int)$DB->count_records('local_btiportal_fees_payment_attempt');
    $r = $t ? ws($t, 'local_btiportal_initialize_fees_payment') : '{"error":"no token"}';
    $a = (int)$DB->count_records('local_btiportal_fees_payment_attempt');
    cli_writeln("initialize $u attempts $b->$a $r");
}
foreach (['fee.instructor', 'fee.admin'] as $u) {
    $t = dev_token($u, $pass);
    $b = (int)$DB->count_records('local_btiportal_fees_payment_attempt');
    $r = $t ? ws($t, 'local_btiportal_verify_fees_payment', ['reference' => 'NO-SUCH']) : '{"error":"no token"}';
    $a = (int)$DB->count_records('local_btiportal_fees_payment_attempt');
    cli_writeln("verify $u attempts $b->$a $r");
}

$unpaidid = (int)$DB->get_field('user', 'id', ['username' => 'fee.unpaid']);
cli_writeln('');
cli_writeln("=== set_fees_paid + upsert_student (userid=$unpaidid) ===");
foreach (['fee.unpaid', 'fee.instructor'] as $u) {
    $t = dev_token($u, $pass);
    cli_writeln("set_fees_paid $u " . ($t ? ws($t, 'local_btiportal_set_fees_paid', [
        'userid' => $unpaidid,
        'source' => 'manual',
        'reference' => 'dev-deny',
    ]) : '{"error":"no token"}'));
}
$prog = (int)$DB->get_field_sql('SELECT id FROM {local_btiportal_programme} ORDER BY id ASC', []);
foreach (['fee.unpaid', 'fee.instructor'] as $u) {
    $t = dev_token($u, $pass);
    cli_writeln("upsert_student $u " . ($t ? ws($t, 'local_btiportal_upsert_student', [
        'userid' => $unpaidid,
        'programme_id' => $prog,
        'year_of_study' => 1,
    ]) : '{"error":"no token"}'));
}

cli_writeln('');
cli_writeln('=== core WS leak (fee.unpaid, gate must be ON) ===');
$t = dev_token('fee.unpaid', $pass);
$courseid = (int)$DB->get_field_sql(
    "SELECT c.id FROM {course} c WHERE c.shortname = 'BAC001' ORDER BY c.id ASC",
    []
);
foreach ([
    'core_enrol_get_users_courses' => ['userid' => $unpaidid],
    'core_course_get_contents' => ['courseid' => $courseid],
    'core_calendar_get_calendar_events' => ['events' => json_encode([]), 'options' => json_encode([])],
    'gradereport_user_get_grade_items' => ['courseid' => $courseid, 'userid' => $unpaidid],
] as $fn => $params) {
    cli_writeln("$fn " . ($t ? ws($t, $fn, $params) : 'no token'));
}

cli_writeln('');
cli_writeln('=== ensure_my_pathway_enrolments (fee.unpaid) ===');
cli_writeln($t ? ws($t, 'local_btiportal_ensure_my_pathway_enrolments') : 'no token');

cli_writeln('');
cli_writeln('=== gated local_btiportal (23) ===');
$passc = 0;
$failc = 0;
foreach (fees_gate_registry::GATED as $fn) {
    $body = $t ? ws($t, $fn) : '';
    $ok = $body !== '' && str_contains($body, 'feesunpaid');
    if ($ok) {
        $passc++;
    } else {
        $failc++;
    }
    cli_writeln(($ok ? 'PASS' : 'FAIL') . "\t$fn\t" . substr($body, 0, 100));
}
cli_writeln("Summary gated $passc/" . count(fees_gate_registry::GATED) . " fail=$failc");