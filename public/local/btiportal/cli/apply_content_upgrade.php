<?php
/**
 * One-shot: create announcement/recording tables and register new WS functions.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

unset_config('upgraderunning');

$announcements = $CFG->prefix . 'local_btiportal_announcement';
$recordings = $CFG->prefix . 'local_btiportal_recording';

$exists = $DB->record_exists_sql(
    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
    [$announcements]
);
if (!$exists) {
    $DB->execute("
        CREATE TABLE {$announcements} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            courseid BIGINT NOT NULL,
            authorid BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            eventid BIGINT NOT NULL DEFAULT 0,
            timecreated BIGINT NOT NULL,
            KEY courseid_idx (courseid),
            KEY authorid_idx (authorid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    cli_writeln("Created {$announcements}");
} else {
    cli_writeln("Table {$announcements} already exists");
}

$exists = $DB->record_exists_sql(
    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
    [$recordings]
);
if (!$exists) {
    $DB->execute("
        CREATE TABLE {$recordings} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            courseid BIGINT NOT NULL,
            authorid BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'class',
            sessiondate VARCHAR(32) NOT NULL,
            description LONGTEXT NOT NULL,
            link LONGTEXT,
            filename VARCHAR(255),
            timecreated BIGINT NOT NULL,
            KEY courseid_idx (courseid),
            KEY authorid_idx (authorid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    cli_writeln("Created {$recordings}");
} else {
    cli_writeln("Table {$recordings} already exists");
}

// Register external_functions from services.php definitions.
$defs = [
    'local_btiportal_create_course_calendar_events' => 'local_btiportal\\external\\create_course_calendar_events',
    'local_btiportal_publish_announcement' => 'local_btiportal\\external\\publish_announcement',
    'local_btiportal_get_announcements' => 'local_btiportal\\external\\get_announcements',
    'local_btiportal_publish_recording' => 'local_btiportal\\external\\publish_recording',
    'local_btiportal_get_recordings' => 'local_btiportal\\external\\get_recordings',
];

foreach ($defs as $name => $classname) {
    $existing = $DB->get_record('external_functions', ['name' => $name]);
    $rec = (object) [
        'name' => $name,
        'classname' => $classname,
        'methodname' => 'execute',
        'classpath' => null,
        'component' => 'local_btiportal',
        'capabilities' => '',
        'services' => null,
    ];
    if ($existing) {
        $rec->id = $existing->id;
        $DB->update_record('external_functions', $rec);
        cli_writeln("Updated external_functions {$name}");
    } else {
        $DB->insert_record('external_functions', $rec);
        cli_writeln("Inserted external_functions {$name}");
    }
}

foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        cli_writeln("Service {$shortname} missing — skip");
        continue;
    }
    foreach (array_keys($defs) as $fn) {
        if (!$DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ])) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
            cli_writeln("Attached {$fn} to {$shortname}");
        }
    }
}

set_config('version', 2026072019, 'local_btiportal');
cli_writeln('Plugin version set to 2026072019');
cli_writeln('Done.');
