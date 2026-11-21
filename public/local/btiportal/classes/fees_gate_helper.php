<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Server-side fees gate for portal learning data (when feesgate_enabled is on).
 */
class fees_gate_helper {
    public const ERROR_CODE = 'feesunpaid';

    /** @var list<string> WS function names never gated by fees (local_btiportal_* only). */
    public const ALLOWLIST = [
        'local_btiportal_get_student_context',
        'local_btiportal_list_fees_message_contacts',
        'local_btiportal_send_fees_message',
        'local_btiportal_initialize_fees_payment',
        'local_btiportal_verify_fees_payment',
        'local_btiportal_set_fees_paid',
        'local_btiportal_upsert_student',
        'local_btiportal_get_current_user_context',
        'local_btiportal_get_my_portal_role',
        'local_btiportal_change_own_password',
        'local_btiportal_get_password_policy',
        'local_btiportal_email_temporary_password',
        'local_btiportal_complete_password_reset',
        'local_btiportal_authorize_portal_user',
        'local_btiportal_confirm_my_course_request_payment',
        'local_btiportal_create_my_course_request',
        'local_btiportal_list_my_course_requests',
    ];

    public static function is_enabled(): bool {
        return (bool) get_config('local_btiportal', 'feesgate_enabled');
    }

    /**
     * Staff / admin users are never subject to the fees gate (Moodle capabilities & roles).
     */
    public static function is_exempt_user(int $userid): bool {
        if ($userid <= 0 || isguestuser($userid)) {
            return true;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        $ctx = \context_system::instance();
        if (has_capability('moodle/site:config', $ctx, $userid)) {
            return true;
        }
        if (has_capability('local/btiportal:manageacademic', $ctx, $userid)
            || has_capability('local/btiportal:viewacademic', $ctx, $userid)
            || has_capability('local/btiportal:managestudentfees', $ctx, $userid)) {
            return true;
        }

        global $DB;
        $roles = $DB->get_fieldset_sql(
            'SELECT DISTINCT r.shortname
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = ?',
            [$userid]
        );
        $lower = array_map('strtolower', $roles);
        foreach (['manager', 'editingteacher', 'teacher', 'coursecreator'] as $staffrole) {
            if (in_array($staffrole, $lower, true)) {
                return true;
            }
        }
        return false;
    }

    public static function has_fees_subject_record(int $userid): bool {
        global $DB;
        if ($DB->record_exists('local_btiportal_student_profile', ['userid' => $userid])) {
            return true;
        }
        if (student_fees_helper::table_exists() && student_fees_helper::get_record($userid)) {
            return true;
        }
        return false;
    }

    public static function is_subject_to_gate(int $userid): bool {
        if (self::is_exempt_user($userid)) {
            return false;
        }
        return self::has_fees_subject_record($userid);
    }

    public static function access_allowed(int $userid): bool {
        if (!self::is_enabled()) {
            return true;
        }
        if (!self::is_subject_to_gate($userid)) {
            return true;
        }
        return student_fees_helper::is_paid($userid);
    }

    /**
     * Throw moodle_exception feesunpaid when gate applies and fees are not cleared.
     */
    public static function require_cleared(int $userid): void {
        if (!self::is_enabled()) {
            return;
        }
        if (!self::is_subject_to_gate($userid)) {
            return;
        }
        if (student_fees_helper::is_paid($userid)) {
            return;
        }
        throw new \moodle_exception(self::ERROR_CODE, 'local_btiportal');
    }

    public static function require_cleared_current_user(): void {
        global $USER;
        self::require_cleared((int)$USER->id);
    }

    /**
     * Gate student self-scoped portal learning calls for the signed-in user.
     */
    public static function guard_student_learning(): void {
        global $USER;
        academic_auth_helper::require_student_self_read();
        self::require_cleared((int)$USER->id);
    }
}