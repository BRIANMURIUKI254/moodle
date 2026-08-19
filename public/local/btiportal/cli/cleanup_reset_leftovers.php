<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $DB, $CFG;
require_once($CFG->libdir . '/filelib.php');

$fs = get_file_storage();
$syscontext = context_system::instance();
foreach (['recording', 'resource', 'eventimage', 'courseimage', 'certificate', 'programmeimage', 'courseunitimage'] as $filearea) {
    $fs->delete_area_files($syscontext->id, 'local_btiportal', $filearea);
    echo "cleared {$filearea}\n";
}

if ($DB->get_manager()->table_exists('messages')) {
    $count = (int)$DB->count_records('messages');
    $DB->delete_records('messages');
    echo "messages_deleted={$count}\n";
}
if ($DB->get_manager()->table_exists('message_user_actions')) {
    $DB->delete_records('message_user_actions');
}

$left = (int)$DB->count_records_select(
    'files',
    "component = 'local_btiportal' AND filename <> '.' AND filename <> '..'"
);
echo "portal_files_remaining={$left}\n";
echo "DONE\n";
