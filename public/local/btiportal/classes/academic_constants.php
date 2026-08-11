<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Controlled vocabularies for academic structure tables.
 */
class academic_constants {
    public const LIST_LIMIT = 500;

    public const PROGRAMME_STATUSES = ['active', 'inactive', 'archived'];

    public const CURRICULUM_STATUSES = ['draft', 'active', 'retired', 'archived'];

    public const YEAR_STATUSES = ['upcoming', 'active', 'completed', 'archived'];

    public const SEMESTER_STATUSES = ['upcoming', 'active', 'completed', 'archived'];

    public const UNIT_TYPES = ['core', 'elective', 'optional'];

    public const UNIT_STATUSES = ['draft', 'active', 'inactive', 'retired', 'archived'];

    public const TEACHING_ROLES = [
        'course_coordinator',
        'primary_lecturer',
        'co_lecturer',
        'tutorial_lecturer',
        'laboratory_instructor',
        'visiting_lecturer',
    ];

    public const TEACHER_STATUSES = ['active', 'inactive', 'removed'];

    public const ROLE_COURSE_COORDINATOR = 'course_coordinator';
    public const ROLE_PRIMARY_LECTURER = 'primary_lecturer';

    // ===== Phase 3 academic catalogue =====

    public const SCHOOL_STATUSES = ['active', 'inactive', 'archived'];

    public const DEPARTMENT_STATUSES = ['active', 'inactive', 'archived'];

    public const AWARD_TYPES = ['certificate', 'diploma', 'bachelor', 'master', 'doctorate', 'other'];

    public const DURATION_UNITS = ['years', 'semesters', 'months', 'weeks'];

    public const ADMISSION_STATUSES = ['pending', 'admitted', 'active', 'deferred', 'withdrawn', 'completed'];

    public const ACADEMIC_STATUSES = ['active', 'probation', 'deferred', 'withdrawn', 'completed', 'suspended'];

    public const MEMBERSHIP_ROLES = ['member', 'head', 'deputy_head', 'coordinator', 'adjunct'];

    public const ORG_STATUSES = ['active', 'inactive', 'archived'];

    public const YEAR_OF_STUDY_STATUSES = ['active', 'inactive', 'archived'];

    public const CURRICULUM_SEMESTER_STATUSES = ['active', 'inactive', 'archived'];

    public const STUDENT_PROFILE_STATUSES = ['active', 'inactive', 'archived'];

    // ===== Phase 25 student academic lifecycle =====

    public const LIFECYCLE_ACTION_TYPES = [
        'admission',
        'promotion',
        'semester_progression',
        'deferral',
        'return_from_deferral',
        'programme_transfer',
        'programme_change',
        'withdrawal',
        'readmission',
        'graduation',
        'placement_update',
    ];

    public const LIFECYCLE_APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'recorded'];

    /** Moodle course enrolment transition policies for lifecycle actions. */
    public const ENROLMENT_POLICIES = [
        'preserve',
        'end_active',
        'unenrol_previous',
        'enrol_new',
        'end_and_enrol',
        'manual_followup',
    ];

    public const MOODLE_TEACHING_ROLES = ['editingteacher', 'teacher'];

    // ===== Phase 26 curriculum delivery + enrolment planning =====

    public const DELIVERY_ENROLMENT_STATES = [
        'enrolled',
        'not_enrolled',
        'completed',
        'ended',
        'manual_review',
        'unmapped',
        'missing_moodle_course',
        'not_required',
    ];

    public const ENROLMENT_OPERATION_TYPES = [
        'admission_enrolment',
        'return_enrolment',
        'programme_transfer_enrolment',
        'programme_change_enrolment',
        'readmission_enrolment',
        'year_progression_enrolment',
        'semester_progression_enrolment',
        'manual_reconciliation',
    ];

    public const ENROLMENT_OPERATION_STATUSES = [
        'previewed',
        'completed',
        'partial',
        'failed',
        'skipped',
    ];

    public const REQUIREMENT_TYPES = ['required', 'elective', 'optional'];

    // ===== Phase 27 electives + progression eligibility + reconciliation =====

    public const ELECTIVE_GROUP_STATUSES = ['active', 'inactive', 'archived'];

    public const STUDENT_ELECTIVE_STATUSES = ['selected', 'confirmed', 'withdrawn'];

    public const PROGRESSION_ELIGIBILITY_STATES = [
        'eligible',
        'not_eligible',
        'pending_results',
        'manual_approval_required',
        'rules_not_configured',
    ];

    public const PROGRESSION_RULE_STATUSES = ['active', 'inactive', 'archived'];

    public const DELIVERY_COMPARE_ACTIONS = [
        'retain',
        'already_enrolled',
        'add',
        'end_active',
        'unenrol',
        'completed_history_retained',
        'grade_history_retained',
        'manual_review',
        'unmapped',
        'elective_decision_required',
    ];

    /** Transfer/return enrolment transition policies (Admin-controlled). */
    public const DELIVERY_TRANSITION_POLICIES = [
        'preserve_and_enrol_new', // default: preserve history, enrol approved new required
        'enrol_new_only',
        'end_active_and_enrol_new',
        'unenrol_previous_and_enrol_new',
        'manual_review_only',
    ];
}
