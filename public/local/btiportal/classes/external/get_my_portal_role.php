<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\portal_role_helper;

/**
 * Return the BTI portal navigation role for the signed-in Moodle user.
 * Used immediately after login/token.php to route the frontend dashboard.
 */
class get_my_portal_role extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), []);
        $context = \context_system::instance();
        self::validate_context($context);
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('noguest', 'error');
        }
        return portal_role_helper::resolve_for_user((int)$USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'portalrole' => new external_value(
                PARAM_ALPHA,
                'Primary portal dashboard bucket: admin, instructor, or student'
            ),
            'issiteadmin' => new external_value(PARAM_BOOL, 'Moodle Site Administrator'),
            'isuniversityadmin' => new external_value(
                PARAM_BOOL,
                'Site admin or holder of local/btiportal:manageacademic'
            ),
            'isdepartmentleader' => new external_value(PARAM_BOOL, 'Active Department Leader assignment'),
            'canmanageacademic' => new external_value(PARAM_BOOL, 'local/btiportal:manageacademic at system context'),
            'canviewacademic' => new external_value(PARAM_BOOL, 'local/btiportal:viewacademic at system context'),
            'roles' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Moodle role shortname')
            ),
            'isinstructor' => new external_value(
                PARAM_BOOL,
                'Editing teacher/teacher role or active teaching-section assignment'
            ),
            'isstudent' => new external_value(
                PARAM_BOOL,
                'Course student role and/or academic student profile'
            ),
            'hasteachingsections' => new external_value(PARAM_BOOL, 'Mapped to at least one portal teaching section'),
            'hasstudentprofile' => new external_value(PARAM_BOOL, 'Row in local_btiportal_student_profile'),
            'hascoursestudentrole' => new external_value(PARAM_BOOL, 'Student role in at least one course'),
        ]);
    }
}
