<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

global $DB;

use local_btiportal\student_profile_helper;
use local_btiportal\student_portal_helper;

$rows = $DB->get_records_sql(
    "SELECT sp.*, u.firstname, u.lastname, u.email, u.username, u.idnumber, u.suspended,
            u.phone1, u.department
       FROM {local_btiportal_student_profile} sp
       JOIN {user} u ON u.id = sp.userid AND u.deleted = 0",
    [],
    0,
    5
);

foreach ($rows as $row) {
    cli_writeln('Row userid=' . $row->userid);
    try {
        $profile = student_profile_helper::format($row);
        cli_writeln('  format ok');
        $enriched = student_portal_helper::enrich_profile($profile);
        cli_writeln('  enrich ok programme=' . ($enriched['programmename'] ?? ''));
        $full = student_profile_helper::format_directory_row($row);
        cli_writeln('  directory row ok: ' . $full['fullname']);
    } catch (Throwable $e) {
        cli_writeln('  ERROR: ' . $e->getMessage());
        cli_writeln('  ' . $e->getTraceAsString());
    }
}
