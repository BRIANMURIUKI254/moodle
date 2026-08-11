<?php
namespace local_btiportal\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use local_btiportal\student_semester_sync_helper;

/**
 * Unlock next curriculum semester for students whose institutional semester has ended.
 */
class sync_student_semesters extends scheduled_task {
    public function get_name() {
        return get_string('task_syncstudentsemesters', 'local_btiportal');
    }

    public function execute() {
        $result = student_semester_sync_helper::sync_all_due(500);
        mtrace('local_btiportal semester sync: checked=' . $result['checked']
            . ' advanced=' . $result['advanced']);
    }
}
