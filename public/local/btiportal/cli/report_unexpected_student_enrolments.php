<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\student_enrolment_reconcile_helper;

list($options, $unrecognized) = cli_get_params(['help' => false], ['h' => 'help']);
if (!empty($options['help'])) {
    cli_writeln('Read-only report of student enrolments that current entitlement rules would not create.');
    cli_writeln('Does not unenrol anyone.');
    cli_writeln('  php local/btiportal/cli/report_unexpected_student_enrolments.php');
    exit(0);
}

$rows = student_enrolment_reconcile_helper::unexpected_enrolments();
cli_heading('Unexpected student enrolments (review only)');
foreach ($rows as $row) {
    cli_writeln(sprintf(
        'userid=%d courseid=%d %s reason=%s',
        $row['userid'],
        $row['courseid'],
        $row['shortname'],
        $row['reason']
    ));
}
cli_writeln('count=' . count($rows));
exit(0);
