<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;

echo "===== leftover messages =====\n";
foreach ($DB->get_records('messages') as $m) {
    echo json_encode($m) . PHP_EOL;
}
if ($DB->get_manager()->table_exists('message_conversations')) {
    echo "CONVERSATIONS=" . $DB->count_records('message_conversations') . PHP_EOL;
}
if ($DB->get_manager()->table_exists('message_conversation_members')) {
    echo "CONVO_MEMBERS=" . $DB->count_records('message_conversation_members') . PHP_EOL;
}

echo "\n===== deleted users =====\n";
foreach ($DB->get_records('user', ['deleted' => 1], 'id') as $u) {
    echo "id={$u->id} username={$u->username} email={$u->email}\n";
}

echo "\n===== leftover local_btiportal files =====\n";
$files = $DB->get_records_select(
    'files',
    "component = 'local_btiportal' AND filename <> '.' AND filename <> '..'"
);
echo "PORTAL_FILES=" . count($files) . PHP_EOL;
foreach ($files as $f) {
    echo "{$f->filearea} {$f->filename} size={$f->filesize}\n";
}

echo "\n===== leftover assign/zoom/forum =====\n";
echo "assign=" . $DB->count_records('assign') . "\n";
echo "zoom=" . ($DB->get_manager()->table_exists('zoom') ? $DB->count_records('zoom') : 'na') . "\n";
echo "forum=" . $DB->count_records('forum') . "\n";
echo "user_enrolments course>1=" . $DB->count_records_select('user_enrolments', 'enrolid IN (SELECT id FROM {enrol} WHERE courseid > 1)') . "\n";

echo "\n===== config_plugins local_btiportal version =====\n";
echo $DB->get_field('config_plugins', 'value', ['plugin' => 'local_btiportal', 'name' => 'version']) . PHP_EOL;
