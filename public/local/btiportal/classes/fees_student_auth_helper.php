<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Tuition payment web services are for enrolled students only (not staff / finance).
 */
class fees_student_auth_helper {
    public static function require_eligible_student_payer(int $userid): void {
        global $DB;

        if ($userid <= 0 || isguestuser($userid)) {
            throw new \moodle_exception('noguest', 'error');
        }

        if (fees_gate_helper::is_exempt_user($userid)) {
            throw new \moodle_exception('feespaymentstudentonly', 'local_btiportal');
        }

        if (!$DB->record_exists('local_btiportal_student_profile', ['userid' => $userid])) {
            throw new \moodle_exception('feespaymentnoprofile', 'local_btiportal');
        }
    }
}