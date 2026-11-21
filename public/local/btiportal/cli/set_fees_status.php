<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\student_fees_helper;

$usage = "Set tuition fees status for a Moodle user (dev/staging ops).

Options:
--username=USERNAME   Moodle username (required)
--paid                Mark fees paid (enrols programme container idempotently)
--unpaid              Mark fees unpaid (does not unenrol courses)
--reference=REF       Payment reference when marking paid (optional)
--source=SOURCE       manual|paystack|migration (default manual when --paid)
-h, --help            Print this help

Examples:
  php local/btiportal/cli/set_fees_status.php --username=fee.unpaid --unpaid
  php local/btiportal/cli/set_fees_status.php --username=fee.paid --paid --reference=DEV-001
";

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'username' => '',
        'paid' => false,
        'unpaid' => false,
        'reference' => '',
        'source' => 'manual',
    ],
    ['h' => 'help']
);

if ($options['help'] || $unrecognized) {
    echo $usage;
    exit($options['help'] ? 0 : 2);
}

$username = trim((string)$options['username']);
if ($username === '') {
    cli_error('Provide --username=');
}
if ($options['paid'] === $options['unpaid']) {
    cli_error('Specify exactly one of --paid or --unpaid');
}

global $DB;

$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$user) {
    cli_error("User not found: {$username}");
}
$userid = (int)$user->id;

if ($options['paid']) {
    $result = student_fees_helper::mark_paid_and_enrol(
        $userid,
        (string)$options['source'],
        (string)$options['reference'],
        0
    );
    cli_writeln(json_encode($result, JSON_PRETTY_PRINT));
    exit($result['ok'] ? 0 : 1);
}

// Mark unpaid: reset or create unpaid row (does not remove enrolments).
$now = time();
$row = student_fees_helper::get_record($userid);
if ($row) {
    $row->status = student_fees_helper::STATUS_UNPAID;
    $row->paid_at = 0;
    $row->source = '';
    $row->reference = '';
    $row->timemodified = $now;
    $DB->update_record('local_btiportal_student_fees', $row);
} else {
    $DB->insert_record('local_btiportal_student_fees', (object) [
        'userid' => $userid,
        'status' => student_fees_helper::STATUS_UNPAID,
        'paid_at' => 0,
        'source' => '',
        'reference' => '',
        'recordedby' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
}
cli_writeln("User {$username} (id {$userid}) fees status set to unpaid.");
exit(0);