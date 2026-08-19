<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $DB, $CFG;

$prefix = $CFG->prefix;
echo "PREFIX={$prefix}\n";
echo "DB={$CFG->dbname}\n\n";

$tables = $DB->get_tables();
$plugin = [];
foreach ($tables as $table) {
    if (strpos($table, 'local_btiportal') === 0) {
        $plugin[] = $table;
    }
}
sort($plugin);

echo "===== local_btiportal tables =====\n";
$totalplugin = 0;
foreach ($plugin as $table) {
    $count = (int)$DB->count_records($table);
    $totalplugin += $count;
    echo sprintf("%-42s %d\n", $table, $count);
}
echo "PLUGIN_TABLES=" . count($plugin) . " PLUGIN_ROWS={$totalplugin}\n\n";

echo "===== Moodle users (deleted=0) =====\n";
$users = $DB->get_records_select('user', 'deleted = 0', [], 'id ASC', 'id,username,email,firstname,lastname,auth,idnumber,timecreated');
foreach ($users as $u) {
    $siteadmin = is_siteadmin($u->id) ? 'SITEADMIN' : '';
    echo "id={$u->id} user={$u->username} email={$u->email} name={$u->firstname} {$u->lastname} auth={$u->auth} {$siteadmin}\n";
}
echo "USER_COUNT=" . count($users) . "\n\n";

echo "===== Moodle courses =====\n";
$courses = $DB->get_records('course', null, 'id ASC', 'id,shortname,fullname,category,visible,format');
foreach ($courses as $c) {
    echo "id={$c->id} short={$c->shortname} full={$c->fullname} cat={$c->category} vis={$c->visible}\n";
}
echo "COURSE_COUNT=" . count($courses) . "\n\n";

echo "===== Enrolments (userid>1, courseid>1) =====\n";
$ues = $DB->get_records_sql(
    "SELECT ue.id, ue.userid, e.courseid, c.shortname, e.enrol, ue.status
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE e.courseid > 1
      ORDER BY ue.userid, e.courseid"
);
foreach ($ues as $row) {
    echo "ue={$row->id} user={$row->userid} course={$row->courseid} {$row->shortname} method={$row->enrol} status={$row->status}\n";
}
echo "ENROL_COUNT=" . count($ues) . "\n\n";

echo "===== Assignments =====\n";
if ($DB->get_manager()->table_exists('assign')) {
    $assigns = $DB->get_records('assign', null, 'id ASC', 'id,course,name');
    foreach ($assigns as $a) {
        echo "assign={$a->id} course={$a->course} name={$a->name}\n";
    }
    echo "ASSIGN_COUNT=" . count($assigns) . "\n";
    $subcount = $DB->count_records('assign_submission');
    echo "ASSIGN_SUBMISSIONS={$subcount}\n\n";
}

echo "===== Forums / discussions =====\n";
if ($DB->get_manager()->table_exists('forum')) {
    echo "FORUMS=" . $DB->count_records('forum') . "\n";
    echo "DISCUSSIONS=" . $DB->count_records('forum_discussions') . "\n";
    echo "POSTS=" . $DB->count_records('forum_posts') . "\n\n";
}

echo "===== Zoom / BigBlueButton =====\n";
foreach (['zoom', 'bigbluebuttonbn'] as $mod) {
    if ($DB->get_manager()->table_exists($mod)) {
        echo strtoupper($mod) . "=" . $DB->count_records($mod) . "\n";
    } else {
        echo strtoupper($mod) . "=table_missing\n";
    }
}

echo "\n===== Messages =====\n";
echo "MESSAGES=" . $DB->count_records('messages') . "\n";
echo "NOTIFICATIONS=" . $DB->count_records('notifications') . "\n";

echo "\n===== Site admins =====\n";
echo "siteadmins=" . get_config('core', 'siteadmins') . "\n";

echo "\n===== Course modules by mod =====\n";
$mods = $DB->get_records_sql(
    "SELECT m.name, COUNT(cm.id) AS cnt
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module
      GROUP BY m.name
      ORDER BY m.name"
);
foreach ($mods as $m) {
    echo "{$m->name}={$m->cnt}\n";
}
