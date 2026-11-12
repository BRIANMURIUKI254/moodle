<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\assessment_helper;
use local_btiportal\student_assessment_entitlement_helper;
use local_btiportal\student_course_access_helper;
use local_btiportal\student_profile_helper;

list($options, $unrecognized) = cli_get_params(
    [
        'execute' => false,
        'help' => false,
        'userid' => 0,
        'courseid' => 0,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognized)) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help'])) {
    cli_writeln('Report (and optionally repair) students who can SEE an assessment but are not enrolled.');
    cli_writeln('Visibility and enrolment both use student_assessment_entitlement_helper.');
    cli_writeln('Default is dry-run. Pass --execute to write enrolments.');
    cli_writeln('');
    cli_writeln('  php local/btiportal/cli/backfill_student_assessment_enrolments.php');
    cli_writeln('  php local/btiportal/cli/backfill_student_assessment_enrolments.php --userid=50 --courseid=26');
    cli_writeln('  php local/btiportal/cli/backfill_student_assessment_enrolments.php --execute');
    exit(0);
}

global $DB;
$execute = !empty($options['execute']);
$onlyuserid = (int)($options['userid'] ?? 0);
$inspectcourseid = (int)($options['courseid'] ?? 0);

$students = student_profile_helper::list_directory('active', 'active');
if ($onlyuserid > 0) {
    $students = array_values(array_filter(
        $students,
        static fn(array $row): bool => (int)($row['userid'] ?? 0) === $onlyuserid
    ));
}

cli_heading($execute ? 'Execute enrolment backfill' : 'Dry-run: visible assessments missing enrolment');
cli_writeln('Active programme students: ' . count($students));

$beforemissing = 0;
$afterenrolled = 0;
$afterreused = 0;
$afterfailed = 0;
$reported = 0;

foreach ($students as $student) {
    $userid = (int)($student['userid'] ?? 0);
    if ($userid <= 0) {
        continue;
    }
    $label = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''))
        . ' #' . $userid
        . ' ' . (string)($student['admissionnumber'] ?? '');

    if ($inspectcourseid > 0) {
        $class = student_assessment_entitlement_helper::classify_course($userid, $inspectcourseid);
        cli_writeln($label . ' course=' . $inspectcourseid . ' ' . json_encode($class));
    }

    $visible = assessment_helper::list_for_courses([], $userid);
    $missing = [];
    $seen = [];
    foreach ($visible as $item) {
        $courseid = (int)($item['courseid'] ?? 0);
        if ($courseid <= 1 || isset($seen[$courseid])) {
            continue;
        }
        $seen[$courseid] = true;
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || is_enrolled($context, $userid, '', true)) {
            continue;
        }
        $kind = student_assessment_entitlement_helper::classify_course($userid, $courseid)['kind'];
        $missing[] = $kind . ':' . $courseid;
    }

    $beforemissing += count($missing);
    if ($missing) {
        $reported++;
        cli_writeln($label . ' visible_without_enrolment=' . implode(',', $missing));
    }

    if (!$execute) {
        continue;
    }

    $result = student_course_access_helper::ensure_pathway_enrolments($userid);
    $afterenrolled += (int)($result['enrolled'] ?? 0);
    $afterreused += (int)($result['reused'] ?? 0);
    $afterfailed += (int)($result['failed'] ?? 0);
    cli_writeln(sprintf(
        '%s enrolled=%d reused=%d failed=%d',
        $label,
        (int)($result['enrolled'] ?? 0),
        (int)($result['reused'] ?? 0),
        (int)($result['failed'] ?? 0)
    ));
}

if (!$execute) {
    cli_writeln("Students with a visibility/enrolment gap: {$reported}");
    cli_writeln("Missing enrolment slots: {$beforemissing}");
    cli_writeln('Re-run with --execute to enrol.');
    exit(0);
}

cli_heading('After');
cli_writeln("newly enrolled={$afterenrolled} reused={$afterreused} failed={$afterfailed}");
exit($afterfailed > 0 ? 1 : 0);
