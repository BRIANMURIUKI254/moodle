<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\student_enrolment_reconcile_helper;

list($options, $unrecognized) = cli_get_params(
    [
        'execute' => false,
        'help' => false,
        'userid' => 0,
        'courseid' => 0,
    ],
    ['h' => 'help']
);

if (!empty($unrecognized)) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if (!empty($options['help'])) {
    cli_writeln('Enrol active programme students onto entitled Moodle courses.');
    cli_writeln('Uses student_assessment_entitlement_helper. Default is dry-run.');
    cli_writeln('  php local/btiportal/cli/backfill_student_assessment_enrolments.php');
    cli_writeln('  php local/btiportal/cli/backfill_student_assessment_enrolments.php --execute');
    exit(0);
}

$execute = !empty($options['execute']);
$report = student_enrolment_reconcile_helper::run_directory($execute);

cli_heading($execute ? 'Execute enrolment backfill' : 'Dry-run enrolment backfill');

cli_heading('Per programme');
foreach ($report['programmes'] as $row) {
    cli_writeln(sprintf(
        'programme=%d %s students=%d enrolled=%d reused=%d failed=%d skipped=%d zerocourse=%d',
        $row['programmeid'],
        $row['code'] !== '' ? $row['code'] : '-',
        $row['students'],
        $row['enrolled'],
        $row['reused'],
        $row['failed'],
        $row['skipped'],
        $row['zerocourse']
    ));
}

if ($report['needssetup']) {
    cli_heading('NEEDS SETUP');
    foreach ($report['needssetup'] as $row) {
        cli_writeln(sprintf(
            'programme=%d %s students=%d nocontainer=%d emptycurricula=%s',
            $row['programmeid'],
            $row['code'],
            $row['students'],
            $row['nocontainer'],
            $row['emptycurricula'] ? implode(',', $row['emptycurricula']) : '-'
        ));
        foreach ($row['messages'] as $message) {
            cli_writeln('  ' . $message);
        }
    }
}

if ($report['skipped']) {
    cli_heading('Skipped non-students');
    foreach ($report['skipped'] as $row) {
        cli_writeln($row['label'] . ' reason=' . $row['reason']);
    }
}

if ($report['zerocourse']) {
    cli_heading('Zero-course students');
    foreach ($report['zerocourse'] as $row) {
        cli_writeln($row['label'] . ' reason=' . $row['reason']);
    }
}

cli_writeln(sprintf(
    'totals enrolled=%d reused=%d failed=%d skipped=%d zerocourse=%d needssetup=%d',
    $report['enrolled'],
    $report['reused'],
    $report['failed'],
    count($report['skipped']),
    count($report['zerocourse']),
    $report['needssetupcount']
));

if ($report['needssetupcount'] > 0 || $report['failed'] > 0) {
    exit(2);
}
exit(0);
