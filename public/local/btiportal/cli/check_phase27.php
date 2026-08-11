<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

cli_writeln('plugin_version=' . get_config('local_btiportal', 'version'));
cli_writeln('elective_group=' . ($DB->get_manager()->table_exists('local_btiportal_elective_group') ? '1' : '0'));
cli_writeln('elective_group_unit=' . ($DB->get_manager()->table_exists('local_btiportal_elective_group_unit') ? '1' : '0'));
cli_writeln('student_elective_sel=' . ($DB->get_manager()->table_exists('local_btiportal_student_elective_sel') ? '1' : '0'));
cli_writeln('progression_rule=' . ($DB->get_manager()->table_exists('local_btiportal_progression_rule') ? '1' : '0'));

$fns = [
    'local_btiportal_list_elective_groups',
    'local_btiportal_compare_academic_delivery',
    'local_btiportal_reconcile_student_enrolments',
    'local_btiportal_execute_enrolment_reconciliation',
    'local_btiportal_get_progression_eligibility',
    'local_btiportal_preview_delivery_for_placement',
];
foreach ($fns as $fn) {
    $exists = $DB->record_exists('external_functions', ['name' => $fn]);
    cli_writeln($fn . '=' . ($exists ? '1' : '0'));
}

cli_writeln('PHASE27_SCHEMA_CHECK_DONE');
