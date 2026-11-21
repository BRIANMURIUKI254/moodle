<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Documented classification for the student fees gate (see FEES_GATE.md in plugin README).
 * Gated functions call fees_gate_helper::require_cleared() when feesgate_enabled is on.
 */
class fees_gate_registry {
  /** @var list<string> */
    public const ALLOWLIST = fees_gate_helper::ALLOWLIST;

    /** @var list<string> Local BTI student learning functions that enforce the gate. */
    public const GATED = [
        'local_btiportal_get_my_student_workspace',
        'local_btiportal_get_my_student_academic_profile',
        'local_btiportal_get_my_student_delivery_plan',
        'local_btiportal_get_my_student_academic_history',
        'local_btiportal_get_my_progression_eligibility',
        'local_btiportal_list_my_elective_groups',
        'local_btiportal_list_my_student_elective_selections',
        'local_btiportal_save_my_student_elective_selections',
        'local_btiportal_list_my_student_timetable_entries',
        'local_btiportal_ensure_my_pathway_enrolments',
        'local_btiportal_get_my_course_access',
        'local_btiportal_get_my_final_exam_attempt_status',
        'local_btiportal_get_my_enrolled_programme_courses',
        'local_btiportal_get_my_gradebook',
        'local_btiportal_get_assessments',
        'local_btiportal_get_assessment_access',
        'local_btiportal_launch_quiz_attempt',
        'local_btiportal_get_recordings',
        'local_btiportal_get_resources',
        'local_btiportal_get_events',
        'local_btiportal_get_announcements',
        'local_btiportal_get_certificates',
        'local_btiportal_ensure_certificate',
    ];
}