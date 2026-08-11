<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only compatibility overlay for legacy local_btiportal_unit teacher mappings.
 *
 * Does not auto-migrate. Future migration (document only):
 *   old teacheruserid → new row with teachingrole=primary_lecturer, isprimary=1, status=active.
 */
class academic_teacher_compat_helper {
    /**
     * If the academic course unit maps to a Moodle section that still has a legacy
     * local_btiportal_unit teacher, return a synthetic teacher row for UI merging.
     */
    public static function legacy_for_course_unit(int $courseunitid): ?array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_btiportal_unit')) {
            return null;
        }
        if (!$DB->get_manager()->table_exists('local_btiportal_course_unit')) {
            return null;
        }

        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit) {
            return null;
        }

        $legacy = $DB->get_record('local_btiportal_unit', [
            'courseid' => (int)$unit->courseid,
            'sectionid' => (int)$unit->sectionid,
        ]);
        if (!$legacy) {
            return null;
        }

        // Skip if an active new-table primary already covers this user.
        if ($DB->get_manager()->table_exists('local_btiportal_course_unit_teacher')) {
            $exists = $DB->record_exists('local_btiportal_course_unit_teacher', [
                'courseunitid' => $courseunitid,
                'userid' => (int)$legacy->teacheruserid,
                'status' => 'active',
            ]);
            if ($exists) {
                return null;
            }
        }

        return [
            'id' => 0,
            'courseunitid' => $courseunitid,
            'userid' => (int)$legacy->teacheruserid,
            'teachingrole' => academic_constants::ROLE_PRIMARY_LECTURER,
            'isprimary' => 1,
            'status' => 'active',
            'assignedby' => 0,
            'timeassigned' => (int)($legacy->timecreated ?? 0),
            'timeremoved' => 0,
            'islegacy' => 1,
        ];
    }
}
