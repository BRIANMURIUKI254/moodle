<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB, $USER;
$admin = $DB->get_record('user', ['username' => 'portal_admin', 'mnethostid' => $CFG->mnet_localhost_id], '*', MUST_EXIST);
\core\session\manager::set_user($admin);

try {
    $result = \local_btiportal\content_helper::publish_event(
        (int)SITEID,
        (int)$admin->id,
        'CLI Image Event',
        'Body from CLI',
        time(),
        3600,
        0
    );
    cli_writeln(json_encode($result));
} catch (Throwable $e) {
    cli_writeln('ERR: ' . get_class($e) . ' ' . $e->getMessage());
    cli_writeln($e->getTraceAsString());
}
