<?php
/**
 * Checkpoint 1 sample: dump get_unit_gradebook for course 4 / unit 10 as portal_instructor.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/externallib.php');

use core_external\external_api;
use local_btiportal\external\get_unit_gradebook;

global $DB;

$teacher = $DB->get_record('user', ['username' => 'portal_instructor', 'deleted' => 0], '*', MUST_EXIST);
\core\session\manager::set_user($teacher);

$courseid = 4;
$unitid = 10;

$raw = get_unit_gradebook::execute($courseid, $unitid);
$validated = external_api::clean_returnvalue(get_unit_gradebook::execute_returns(), $raw);

// Compact sample for humans: drop full student list noise if large — keep all for accuracy.
echo json_encode($validated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
