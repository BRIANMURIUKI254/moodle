<?php
/**
 * Dev helper: print academic years / semesters and highlight iscurrent=1 rows.
 */
define('CLI_SCRIPT', true);

require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

global $DB;

$years = $DB->get_records('local_btiportal_academic_year', null, 'id ASC');
cli_writeln('=== Academic years ===');
foreach ($years as $y) {
    $mark = (int) $y->iscurrent === 1 ? ' [CURRENT]' : '';
    cli_writeln("id={$y->id} name={$y->name} code={$y->code} status={$y->status}{$mark}");
}

$sems = $DB->get_records('local_btiportal_semester', null, 'id ASC');
cli_writeln('');
cli_writeln('=== Semesters ===');
foreach ($sems as $s) {
    $mark = (int) $s->iscurrent === 1 ? ' [CURRENT]' : '';
    cli_writeln("id={$s->id} academicyearid={$s->academicyearid} name={$s->name} code={$s->code} status={$s->status}{$mark}");
}

cli_writeln('');
cli_writeln('Production IDs may differ — always read from the target environment.');