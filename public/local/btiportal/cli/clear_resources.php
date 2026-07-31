<?php
/**
 * Clear all portal teaching resources and completion rows.
 *
 * Usage:
 *   php /var/www/html/public/local/btiportal/cli/clear_resources.php
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Clear portal resources');

$completionCount = 0;
$resourceCount = 0;
$filesDeleted = 0;

if ($DB->get_manager()->table_exists('local_btiportal_resource_completion')) {
    $completionCount = $DB->count_records('local_btiportal_resource_completion');
    $DB->delete_records('local_btiportal_resource_completion');
    cli_writeln("Cleared {$completionCount} resource completion row(s).");
}

if ($DB->get_manager()->table_exists('local_btiportal_resource')) {
    $resourceCount = $DB->count_records('local_btiportal_resource');
    $ids = $DB->get_fieldset_select('local_btiportal_resource', 'id', '');
    $DB->delete_records('local_btiportal_resource');
    cli_writeln("Cleared {$resourceCount} resource(s).");

    $fs = get_file_storage();
    $syscontext = context_system::instance();
    foreach ($ids as $id) {
        $files = $fs->get_area_files($syscontext->id, 'local_btiportal', 'resource', (int)$id, 'filename', false);
        foreach ($files as $file) {
            $file->delete();
            $filesDeleted++;
        }
        // Also try course contexts (resources may be stored under course context).
    }
}

// Sweep any leftover resource files under local_btiportal component.
$fs = get_file_storage();
$DB->execute(
    "DELETE FROM {files}
     WHERE component = ?
       AND filearea = ?
       AND filename <> '.'",
    ['local_btiportal', 'resource']
);

cli_writeln("Deleted {$filesDeleted}+ Moodle stored resource file(s).");
purge_all_caches();
cli_writeln('Done.');
