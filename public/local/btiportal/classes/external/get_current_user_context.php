<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\department_auth_helper;

class get_current_user_context extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB, $USER;
        self::validate_parameters(self::execute_parameters(), []);
        $roles = $DB->get_fieldset_sql(
            'SELECT DISTINCT r.shortname FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid WHERE ra.userid = ?',
            [$USER->id]
        );
        $forcepasswordchange = (bool) get_user_preferences('auth_forcepasswordchange', false, $USER);
        $leadership = department_auth_helper::get_leadership_departments((int)$USER->id);
        $primarydeptid = 0;
        foreach ($leadership as $row) {
            if ((int)$row['isprimary'] === 1) {
                $primarydeptid = (int)$row['departmentid'];
                break;
            }
        }
        if ($primarydeptid === 0 && $leadership) {
            $primarydeptid = (int)$leadership[0]['departmentid'];
        }
        $email = trim((string) ($USER->email ?? ''));
        if ($email === '' || !validate_email($email)) {
            // PARAM_EMAIL would abort login after token.php already succeeded.
            $email = '';
        }
        return [
            'roles' => array_values($roles),
            'issiteadmin' => is_siteadmin($USER),
            'email' => $email,
            'forcepasswordchange' => $forcepasswordchange,
            'isuniversityadmin' => department_auth_helper::is_university_admin((int)$USER->id),
            'isdepartmentleader' => !empty($leadership),
            'primaryleadershipdepartmentid' => $primarydeptid,
            'leadershipdepartments' => $leadership,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'roles' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Moodle role shortname')),
            'issiteadmin' => new external_value(PARAM_BOOL, 'Whether the current user is a site administrator'),
            'email' => new external_value(PARAM_RAW_TRIMMED, 'User email address', VALUE_DEFAULT, ''),
            'forcepasswordchange' => new external_value(PARAM_BOOL, 'Whether the user must change password on next login'),
            'isuniversityadmin' => new external_value(PARAM_BOOL, 'University academic/operational admin'),
            'isdepartmentleader' => new external_value(PARAM_BOOL, 'Has active Department Leader assignment'),
            'primaryleadershipdepartmentid' => new external_value(PARAM_INT, 'Primary leadership department id'),
            'leadershipdepartments' => new external_multiple_structure(
                new external_single_structure(department_auth_helper::leadership_structure_keys()),
                'Active Department Leader assignments'
            ),
        ]);
    }
}
