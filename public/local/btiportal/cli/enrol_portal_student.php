<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');

$courseid = 4;
$user = $DB->get_record('user', ['username' => 'portal_student', 'mnethostid' => $CFG->mnet_localhost_id], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$manual = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $courseid], '*', MUST_EXIST);
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$manual->enrol_user($instance, $user->id, $studentrole->id);
cli_writeln("Enrolled portal_student ({$user->id}) in course {$courseid}");
