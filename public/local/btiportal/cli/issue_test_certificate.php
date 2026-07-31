<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$user = $DB->get_record('user', ['username' => 'portal_student'], '*', MUST_EXIST);
$cert = \local_btiportal\content_helper::ensure_certificate_for_course((int)$user->id, 4);
cli_writeln($cert ? json_encode($cert, JSON_PRETTY_PRINT) : 'NOT_ISSUED');
