<?php
namespace local_btiportal\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use local_btiportal\student_enrolment_reconcile_helper;

class reconcile_student_enrolments extends scheduled_task {
    public function get_name(): string {
        return get_string('task_reconcilestudentenrolments', 'local_btiportal');
    }

    public function execute(): void {
        $report = student_enrolment_reconcile_helper::run_directory(true);
        if ($report['needssetupcount'] > 0) {
            debugging(
                'local_btiportal NEEDS SETUP programmes=' . $report['needssetupcount'],
                DEBUG_NORMAL
            );
        }
        foreach ($report['skipped'] as $row) {
            debugging(
                'local_btiportal skipped enrolment ' . $row['label'] . ' reason=' . $row['reason'],
                DEBUG_NORMAL
            );
        }
        foreach ($report['zerocourse'] as $row) {
            debugging(
                'local_btiportal zero-course student ' . $row['label'] . ' reason=' . $row['reason'],
                DEBUG_NORMAL
            );
        }
    }
}
