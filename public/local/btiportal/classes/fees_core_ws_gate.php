<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Block selected Moodle core web services for fees-gated students (unpaid, gate on).
 */
class fees_core_ws_gate {
    /** @var list<string> */
    public const GATED_CORE_FUNCTIONS = [
        'core_enrol_get_users_courses',
        'core_course_get_contents',
        'core_calendar_get_calendar_events',
        'gradereport_user_get_grade_items',
    ];

    public static function should_block(int $userid, string $functionname): bool {
        if (!fees_gate_helper::is_enabled()) {
            return false;
        }
        if (!in_array($functionname, self::GATED_CORE_FUNCTIONS, true)) {
            return false;
        }
        if (!fees_gate_helper::is_subject_to_gate($userid)) {
            return false;
        }
        return !fees_gate_helper::access_allowed($userid);
    }
}