<?php
/**
 * Purge portal demo data: courses (except site), unit mappings, calendar events, messages.
 *
 * Usage: php local/btiportal/cli/purge_portal_data.php
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

cli_heading('BTI Portal data purge');

$courses = $DB->get_records_select('course', 'id > 1', null, 'id ASC');
cli_writeln('Courses to delete: ' . count($courses));

foreach ($courses as $course) {
    cli_writeln("Deleting course {$course->id} ({$course->shortname})…");
    try {
        delete_course($course, false);
        cli_writeln('  OK');
    } catch (Throwable $e) {
        cli_writeln('  Failed: ' . $e->getMessage());
    }
}

if ($DB->get_manager()->table_exists('local_btiportal_unit')) {
    $maps = $DB->count_records('local_btiportal_unit');
    $DB->delete_records('local_btiportal_unit');
    cli_writeln("Cleared {$maps} local_btiportal_unit mapping(s).");
}

$events = $DB->count_records('event');
$DB->delete_records('event');
cli_writeln("Deleted {$events} calendar event(s).");

$msgtables = [
    'message_user_actions',
    'message_conversation_actions',
    'message_conversation_members',
    'message_conversations',
    'message_contact_requests',
    'message_contacts',
    'message_email_messages',
    'messages',
    'notifications',
];
foreach ($msgtables as $table) {
    if (!$DB->get_manager()->table_exists($table)) {
        continue;
    }
    $count = $DB->count_records($table);
    if ($count > 0) {
        $DB->delete_records($table);
        cli_writeln("Cleared {$count} row(s) from {$table}.");
    }
}

purge_all_caches();
cli_writeln('Done. Site course (id=1) and user accounts were kept.');
