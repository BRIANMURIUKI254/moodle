<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\programme_container_helper;

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'populate' => false,
        'report' => true,
        'set' => '',
    ],
    ['h' => 'help']
);

if (!empty($unrecognized)) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if (!empty($options['help'])) {
    cli_writeln('Map programmes to Moodle container courses. Never guesses collisions.');
    cli_writeln('  php local/btiportal/cli/map_programme_containers.php --report');
    cli_writeln('  php local/btiportal/cli/map_programme_containers.php --populate');
    cli_writeln('  php local/btiportal/cli/map_programme_containers.php --set=PROGRAMMEID:COURSEID');
    exit(0);
}

if (!empty($options['set'])) {
    [$programmeid, $courseid] = array_pad(explode(':', (string)$options['set'], 2), 2, '0');
    $result = programme_container_helper::set_container_courseid((int)$programmeid, (int)$courseid, 0);
    cli_writeln(json_encode($result));
    exit(empty($result['ok']) ? 1 : 0);
}

if (!empty($options['populate'])) {
    $result = programme_container_helper::populate_unambiguous();
    cli_heading('Auto-mapped (unambiguous only)');
    foreach ($result['mapped'] as $row) {
        cli_writeln(sprintf(
            'programme=%d %s courseid=%d reason=%s',
            $row['programmeid'],
            $row['code'],
            $row['courseid'],
            $row['reason']
        ));
    }
    cli_heading('Unmapped (needs admin)');
    foreach ($result['unmapped'] as $row) {
        cli_writeln(sprintf(
            'programme=%d %s courseid=%d reason=%s',
            $row['programmeid'],
            $row['code'],
            $row['courseid'],
            $row['reason']
        ));
    }
    cli_writeln('mapped=' . count($result['mapped']) . ' unmapped=' . count($result['unmapped']));
    exit(count($result['unmapped']) > 0 ? 2 : 0);
}

$result = programme_container_helper::report_status();
cli_heading('Mapped (unambiguous or already set)');
foreach ($result['mapped'] as $row) {
    cli_writeln(sprintf(
        'programme=%d %s courseid=%d reason=%s',
        $row['programmeid'],
        $row['code'],
        $row['courseid'],
        $row['reason']
    ));
}
cli_heading('Unmapped (needs admin — not guessed)');
foreach ($result['unmapped'] as $row) {
    cli_writeln(sprintf(
        'programme=%d %s courseid=%d reason=%s',
        $row['programmeid'],
        $row['code'],
        $row['courseid'],
        $row['reason']
    ));
}
cli_writeln('mapped=' . count($result['mapped']) . ' unmapped=' . count($result['unmapped']));
exit(count($result['unmapped']) > 0 ? 2 : 0);
