<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Capability helpers for academic WS.
 */
class academic_auth_helper {
    public static function require_manage_academic(): void {
        $context = \context_system::instance();
        external_api_validate_context($context);
        require_capability('local/btiportal:manageacademic', $context);
    }

    public static function require_view_academic(): void {
        $context = \context_system::instance();
        external_api_validate_context($context);
        require_capability('local/btiportal:viewacademic', $context);
    }

    /**
     * Self-scoped lecturer portal reads (workspace / my timetable).
     *
     * Web service tokens already identify the caller. Do not require
     * local/btiportal:viewacademic at system context — course-level editingteachers
     * typically lack that capability there, which broke every Lecturer teaching page.
     */
    public static function require_lecturer_self_read(): void {
        global $USER;
        $context = \context_system::instance();
        external_api_validate_context($context);
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('noguest', 'error');
        }
    }

    /**
     * Self-scoped student portal reads (academic profile / delivery / timetable).
     *
     * Students must never receive local/btiportal:viewacademic. Identity comes
     * from the Moodle webservice token ($USER); callers must force userid = self.
     */
    public static function require_student_self_read(): void {
        global $USER;
        $context = \context_system::instance();
        external_api_validate_context($context);
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('noguest', 'error');
        }
    }

    /**
     * Resolve which student userid a caller may read.
     * Non-admins are forced to self; empty userid defaults to self.
     */
    public static function resolve_student_userid_for_read(int $requesteduserid = 0): int {
        global $USER;
        self::require_student_self_read();
        $selfid = (int)$USER->id;
        if ($requesteduserid <= 0 || $requesteduserid === $selfid) {
            return $selfid;
        }
        // Admins with viewacademic may still use admin endpoints for other users;
        // student self-scoped endpoints never allow cross-user reads.
        throw new \moodle_exception(
            'nopermissions',
            'error',
            '',
            'You may only view your own student academic records'
        );
    }

    /**
     * Lecturers with viewacademic may only read their own teaching records.
     * University admins / site admins may read any lecturer record.
     */
    public static function require_view_lecturer_record(int $targetuserid): void {
        global $USER;
        self::require_view_academic();
        $targetuserid = (int)$targetuserid;
        if ($targetuserid <= 0) {
            throw new \moodle_exception('invalidparameter', 'error', '', null, 'userid is required');
        }
        if ((int)$USER->id === $targetuserid) {
            return;
        }
        if (is_siteadmin($USER) || department_auth_helper::is_university_admin((int)$USER->id)) {
            return;
        }
        throw new \moodle_exception(
            'nopermissions',
            'error',
            '',
            'You may only view your own lecturer teaching records'
        );
    }

    /**
     * Resolve which lecturer userid a caller may read.
     * Non-admins are forced to self; empty userid defaults to self for lecturers.
     */
    public static function resolve_lecturer_userid_for_read(int $requesteduserid = 0): int {
        global $USER;
        self::require_view_academic();
        $selfid = (int)$USER->id;
        if ($requesteduserid <= 0) {
            return $selfid;
        }
        self::require_view_lecturer_record($requesteduserid);
        return (int)$requesteduserid;
    }

    /**
     * Course Unit / prereq / teacher writes: course:update AND manageacademic.
     */
    public static function require_course_unit_write(int $courseid): void {
        $coursecontext = \context_course::instance($courseid);
        external_api_validate_context($coursecontext);
        require_capability('moodle/course:update', $coursecontext);
        require_capability('local/btiportal:manageacademic', \context_system::instance());
    }

    public static function require_course_unit_read(int $courseid): void {
        $coursecontext = \context_course::instance($courseid);
        external_api_validate_context($coursecontext);
        require_capability('moodle/course:view', $coursecontext);
        require_capability('local/btiportal:viewacademic', \context_system::instance());
    }
}

/**
 * Thin wrapper so helpers don't depend on external_api statically incorrectly.
 */
function external_api_validate_context(\context $context): void {
    \core_external\external_api::validate_context($context);
}
