<?php
/**
 * CLI: validate student workspace payload against Moodle external_api structure.
 * Usage: php local/btiportal/cli/validate_student_workspace.php [userid]
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/externallib.php');

list($options, $unrecognized) = cli_get_params([
    'userid' => 0,
    'help' => false,
], ['h' => 'help']);

if (!empty($options['help'])) {
    echo "Validate local_btiportal_get_my_student_workspace encoding.\n";
    echo "  --userid=N  Moodle user id (defaults to latest student profile)\n";
    exit(0);
}

global $DB, $USER;

$userid = (int)$options['userid'];
if ($userid <= 0) {
    $userid = (int)$DB->get_field_sql(
        "SELECT p.userid
           FROM {local_btiportal_student_profile} p
           JOIN {user} u ON u.id = p.userid AND u.deleted = 0
       ORDER BY p.id DESC",
        null,
        IGNORE_MULTIPLE
    );
}
if ($userid <= 0) {
    cli_error('No student profile found. Pass --userid=');
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
\core\session\manager::set_user($user);

cli_writeln("Validating workspace for userid={$userid} ({$user->username})");

$api = new \local_btiportal\external\get_my_student_workspace();
$raw = \local_btiportal\external\get_my_student_workspace::execute(0, 0);
$structure = \local_btiportal\external\get_my_student_workspace::execute_returns();

try {
    $cleaned = external_api::clean_returnvalue($structure, $raw);
    cli_writeln('OK: clean_returnvalue passed');
    $data = $cleaned['data'] ?? [];
    cli_writeln('hasprofile=' . (int)($data['hasprofile'] ?? 0));
    cli_writeln('username=' . ($data['username'] ?? ''));
    cli_writeln('idnumber=' . ($data['idnumber'] ?? ''));
    cli_writeln('programme=' . ($data['profile']['programmename'] ?? ''));
    cli_writeln('school=' . ($data['profile']['schoolname'] ?? ''));
    cli_writeln('department=' . ($data['profile']['departmentname'] ?? ''));
    cli_writeln('eligibility.state=' . ($data['eligibility']['state'] ?? ''));
    cli_writeln('rule_present=' . (array_key_exists('rule', $data['eligibility'] ?? []) ? 'yes' : 'no'));
    exit(0);
} catch (Throwable $e) {
    cli_error('FAIL: ' . $e->getMessage());
}
