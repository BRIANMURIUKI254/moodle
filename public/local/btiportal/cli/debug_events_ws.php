<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

cli_writeln('events=' . $DB->count_records('local_btiportal_event'));
$admin = $DB->get_record('user', ['username' => 'portal_admin'], '*', MUST_EXIST);
\core\session\manager::set_user($admin);
cli_writeln('is_siteadmin=' . (is_siteadmin() ? 'yes' : 'no'));
cli_writeln('userid=' . $USER->id);

try {
    $result = \local_btiportal\external\get_events::execute([]);
    cli_writeln('get_events count=' . count($result['events']));
    cli_writeln(json_encode($result['events'][0] ?? null));
} catch (Throwable $e) {
    cli_writeln('get_events ERR: ' . $e->getMessage());
    cli_writeln($e->getTraceAsString());
}

try {
    $result = \local_btiportal\external\publish_event::execute(1, 'WS Path Event', 'hello', time(), 0, 0);
    cli_writeln('publish ok id=' . $result['id']);
} catch (Throwable $e) {
    cli_writeln('publish ERR: ' . $e->getMessage());
    cli_writeln($e->getTraceAsString());
}
