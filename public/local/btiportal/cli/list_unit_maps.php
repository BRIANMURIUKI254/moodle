<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
$rows = $DB->get_records_sql(
    'SELECT u.username, m.courseid, m.sectionid
     FROM {local_btiportal_unit} m
     JOIN {user} u ON u.id = m.teacheruserid'
);
foreach ($rows as $r) {
    echo "{$r->username} course={$r->courseid} section={$r->sectionid}\n";
}
