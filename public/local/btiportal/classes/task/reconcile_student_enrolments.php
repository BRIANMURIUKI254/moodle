<?php
namespace local_btiportal\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use local_btiportal\student_course_access_helper;
use local_btiportal\student_profile_helper;

class reconcile_student_enrolments extends scheduled_task {
    public function get_name(): string {
        return get_string('task_reconcilestudentenrolments', 'local_btiportal');
    }

    public function execute(): void {
        $students = student_profile_helper::list_directory('active', 'active');
        foreach ($students as $student) {
            $userid = (int)($student['userid'] ?? 0);
            if ($userid <= 0) {
                continue;
            }
            try {
                student_course_access_helper::ensure_pathway_enrolments($userid);
            } catch (\Throwable $e) {
                debugging(
                    'local_btiportal enrolment reconcile failed for user ' . $userid . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }
}
