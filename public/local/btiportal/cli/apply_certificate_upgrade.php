<?php
/**
 * Apply certificate table + WS registration for local_btiportal 2026072021.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php');

unset_config('upgraderunning');

$certs = $CFG->prefix . 'local_btiportal_certificate';
$exists = $DB->record_exists_sql(
    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
    [$certs]
);
if (!$exists) {
    $DB->execute("
        CREATE TABLE {$certs} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            userid BIGINT NOT NULL,
            courseid BIGINT NOT NULL,
            studentname VARCHAR(255) NOT NULL,
            coursename VARCHAR(255) NOT NULL,
            coursecode VARCHAR(100) NOT NULL DEFAULT '',
            completedat BIGINT NOT NULL,
            fileurl LONGTEXT,
            filename VARCHAR(255),
            timecreated BIGINT NOT NULL,
            UNIQUE KEY user_course_uq (userid, courseid),
            KEY courseid_idx (courseid),
            KEY userid_idx (userid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    cli_writeln("Created {$certs}");
} else {
    cli_writeln("Table {$certs} already exists");
}

external_update_descriptions('local_btiportal');

$fns = [
    'local_btiportal_get_certificates',
    'local_btiportal_ensure_certificate',
];
foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        continue;
    }
    foreach ($fns as $fn) {
        if (!$DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ])) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
            cli_writeln("Linked {$fn} to {$shortname}");
        }
    }
}

set_config('version', 2026072021, 'local_btiportal');
purge_all_caches();
cli_writeln('Certificate upgrade applied.');
