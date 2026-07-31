<?php
/**
 * One-shot: create event/resource tables and register WS functions.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

unset_config('upgraderunning');

$events = $CFG->prefix . 'local_btiportal_event';
$resources = $CFG->prefix . 'local_btiportal_resource';
$completions = $CFG->prefix . 'local_btiportal_resource_completion';

foreach ([
    $events => "
        CREATE TABLE {$events} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            courseid BIGINT NOT NULL,
            authorid BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            timestart BIGINT NOT NULL,
            timeduration BIGINT NOT NULL DEFAULT 0,
            eventid BIGINT NOT NULL DEFAULT 0,
            imageurl LONGTEXT,
            filename VARCHAR(255),
            timecreated BIGINT NOT NULL,
            KEY courseid_idx (courseid),
            KEY timestart_idx (timestart)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    $resources => "
        CREATE TABLE {$resources} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            courseid BIGINT NOT NULL,
            sectionid BIGINT NOT NULL DEFAULT 0,
            authorid BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT,
            link LONGTEXT,
            filename VARCHAR(255),
            timecreated BIGINT NOT NULL,
            KEY courseid_idx (courseid),
            KEY sectionid_idx (sectionid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    $completions => "
        CREATE TABLE {$completions} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            resourceid BIGINT NOT NULL,
            userid BIGINT NOT NULL,
            timecreated BIGINT NOT NULL,
            UNIQUE KEY resource_user_uq (resourceid, userid),
            KEY userid_idx (userid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
] as $table => $sql) {
    $exists = $DB->record_exists_sql(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    );
    if (!$exists) {
        $DB->execute($sql);
        cli_writeln("Created {$table}");
    } else {
        cli_writeln("Table {$table} already exists");
    }
}

$fns = [
    'local_btiportal_publish_event',
    'local_btiportal_get_events',
    'local_btiportal_publish_resource',
    'local_btiportal_get_resources',
    'local_btiportal_set_resource_completion',
];
foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
    if (!$service) {
        cli_writeln("Service {$shortname} not found");
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
            cli_writeln("Registered {$fn} on {$shortname}");
        } else {
            cli_writeln("Already registered {$fn} on {$shortname}");
        }
    }
}

set_config('version', 2026072020, 'local_btiportal');
require_once($CFG->libdir . '/upgradelib.php');
external_update_descriptions('local_btiportal');
purge_all_caches();
cli_writeln('Done. Plugin version set to 2026072020.');
