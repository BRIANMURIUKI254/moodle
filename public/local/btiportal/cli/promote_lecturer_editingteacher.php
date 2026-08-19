<?php
/**
 * Promote assigned lecturers from Moodle non-editing teacher to editingteacher
 * on mapped Course Unit Moodle courses only. Idempotent. Does not grant site admin.
 */
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');

use local_btiportal\lecturer_teaching_helper;

$userid = 0;
if (!empty($_SERVER['argv'][1]) && ctype_digit((string)$_SERVER['argv'][1])) {
    $userid = (int)$_SERVER['argv'][1];
}

$result = lecturer_teaching_helper::reconcile_moodle_enrolments($userid);
$record = $result['record'] ?? [];
cli_writeln('ok=' . (!empty($result['ok']) ? '1' : '0'));
cli_writeln('userid=' . (int)($record['userid'] ?? 0));
cli_writeln('count=' . (int)($record['count'] ?? 0));
cli_writeln('connected=' . (int)($record['connected'] ?? 0));
cli_writeln('repaired=' . (int)($record['repaired'] ?? 0));
cli_writeln('unmapped=' . (int)($record['unmapped'] ?? 0));
cli_writeln('failed=' . (int)($record['failed'] ?? 0));
foreach (($record['items'] ?? []) as $item) {
    cli_writeln(sprintf(
        '  user=%d unit=%s moodle=%d status=%s action=%s rolefix',
        (int)($item['userid'] ?? 0),
        (string)($item['unitcode'] ?? ''),
        (int)($item['moodlecourseid'] ?? 0),
        (string)($item['status'] ?? ''),
        (string)($item['action'] ?? '')
    ));
}
exit(empty($result['ok']) ? 1 : 0);
