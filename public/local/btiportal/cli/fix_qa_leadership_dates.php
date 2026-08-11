<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;

$row = $DB->get_record('local_btiportal_lecturer_dept', ['id' => 1]);
echo 'before=' . json_encode($row) . PHP_EOL;
if ($row) {
    $row->timestart = 0;
    $row->timeend = 0;
    $row->isdepartmentleader = 1;
    $row->status = 'active';
    $DB->update_record('local_btiportal_lecturer_dept', $row);
    echo "FIXED leadership dates for assignment #1\n";
}
$now = time();
echo "now={$now}\n";
