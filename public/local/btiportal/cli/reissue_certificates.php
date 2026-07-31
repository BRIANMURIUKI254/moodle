<?php
/**
 * Re-issue premium certificates for users who already have one (regenerates PDF).
 *
 * Usage:
 *   php local/btiportal/cli/reissue_certificates.php [--courseid=N] [--userid=N] [--all]
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'courseid' => 0,
    'userid' => 0,
    'all' => false,
    'portalurl' => '',
    'help' => false,
], [
    'h' => 'help',
    'a' => 'all',
]);

if ($options['help'] || (!$options['all'] && empty($options['courseid']) && empty($options['userid']))) {
    cli_writeln('Re-issue premium BTI certificates (deletes stored PDF and regenerates).');
    cli_writeln('  --courseid=N   Limit to one course');
    cli_writeln('  --userid=N     Limit to one user');
    cli_writeln('  --all          Re-issue every stored certificate');
    cli_writeln('  --portalurl=U  Portal origin for QR links (e.g. http://localhost:8080)');
    exit(0);
}

cli_heading('Re-issue premium certificates');

$params = [];
$where = '1=1';
if (!empty($options['courseid'])) {
    $where .= ' AND courseid = :courseid';
    $params['courseid'] = (int)$options['courseid'];
}
if (!empty($options['userid'])) {
    $where .= ' AND userid = :userid';
    $params['userid'] = (int)$options['userid'];
}

$records = $DB->get_records_select('local_btiportal_certificate', $where, $params, 'id ASC');
cli_writeln('Found ' . count($records) . ' certificate(s).');

// Snapshot targets before delete so we can force-regenerate even if completion slipped.
$targets = [];
foreach ($records as $row) {
    $targets[] = [(int)$row->userid, (int)$row->courseid, (int)$row->id];
}

$fs = get_file_storage();
$context = context_system::instance();
$ok = 0;
$fail = 0;

$portalurl = trim((string)($options['portalurl'] ?? ''));
if ($portalurl === '') {
    $portalurl = 'http://localhost:8080';
}

foreach ($targets as [$userid, $courseid, $oldid]) {
    cli_writeln("Re-issuing id={$oldid} user={$userid} course={$courseid}…");
    try {
        $fs->delete_area_files($context->id, 'local_btiportal', 'certificate', $oldid);
        $DB->delete_records('local_btiportal_certificate', ['id' => $oldid]);
        $cert = \local_btiportal\content_helper::ensure_certificate_for_course($userid, $courseid, $portalurl, true);
        if ($cert) {
            cli_writeln('  OK → ' . ($cert['filename'] ?? 'issued'));
            $ok++;
        } else {
            cli_writeln('  Failed to issue');
            $fail++;
        }
    } catch (Throwable $e) {
        cli_writeln('  Failed: ' . $e->getMessage());
        $fail++;
    }
}

cli_writeln("Done. Issued={$ok} skipped/failed={$fail}");
