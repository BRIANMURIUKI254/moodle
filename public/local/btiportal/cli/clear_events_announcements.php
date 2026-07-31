<?php
/**
 * Clear all portal events and announcements (plugin tables + linked calendar rows).
 *
 * Usage (in Moodle container):
 *   php /var/www/html/public/local/btiportal/cli/clear_events_announcements.php
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Clear portal events & announcements');

$annCount = 0;
$evtCount = 0;
$calDeleted = 0;

if ($DB->get_manager()->table_exists('local_btiportal_announcement')) {
    $annCount = $DB->count_records('local_btiportal_announcement');
    $annRows = $DB->get_records('local_btiportal_announcement', null, '', 'id, eventid');
    foreach ($annRows as $row) {
        if (!empty($row->eventid) && $DB->record_exists('event', ['id' => (int)$row->eventid])) {
            $DB->delete_records('event', ['id' => (int)$row->eventid]);
            $calDeleted++;
        }
    }
    $DB->delete_records('local_btiportal_announcement');
    cli_writeln("Cleared {$annCount} announcement(s).");
} else {
    cli_writeln('Table local_btiportal_announcement not found — skipped.');
}

if ($DB->get_manager()->table_exists('local_btiportal_event')) {
    $evtCount = $DB->count_records('local_btiportal_event');
    $evtRows = $DB->get_records('local_btiportal_event', null, '', 'id, eventid');
    foreach ($evtRows as $row) {
        if (!empty($row->eventid) && $DB->record_exists('event', ['id' => (int)$row->eventid])) {
            $DB->delete_records('event', ['id' => (int)$row->eventid]);
            $calDeleted++;
        }
    }
    $DB->delete_records('local_btiportal_event');
    cli_writeln("Cleared {$evtCount} event(s).");
} else {
    cli_writeln('Table local_btiportal_event not found — skipped.');
}

cli_writeln("Deleted {$calDeleted} linked Moodle calendar event(s).");
purge_all_caches();
cli_writeln('Done.');
