<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolve the BTI portal navigation role for a Moodle user after login.
 */
class portal_role_helper {
    /**
     * @return array{
     *     portalrole:string,
     *     issiteadmin:bool,
     *     isuniversityadmin:bool,
     *     isdepartmentleader:bool,
     *     canmanageacademic:bool,
     *     canviewacademic:bool,
     *     roles:list<string>,
     *     isinstructor:bool,
     *     isstudent:bool,
     *     hasteachingsections:bool,
     *     hasstudentprofile:bool,
     *     hascoursestudentrole:bool
     * }
     */
    public static function resolve_for_user(int $userid): array {
        global $DB;

        if ($userid <= 0 || isguestuser($userid)) {
            throw new \moodle_exception('noguest', 'error');
        }

        $syscontext = \context_system::instance();
        $roles = array_values($DB->get_fieldset_sql(
            'SELECT DISTINCT r.shortname
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = ?',
            [$userid]
        ));
        $roleslower = array_map('strtolower', $roles);

        $issiteadmin = is_siteadmin($userid);
        $isuniversityadmin = department_auth_helper::is_university_admin($userid);
        $isdepartmentleader = !empty(department_auth_helper::get_leadership_departments($userid));
        $canmanageacademic = has_capability('local/btiportal:manageacademic', $syscontext, $userid);
        $canviewacademic = has_capability('local/btiportal:viewacademic', $syscontext, $userid);

        $hasteacherrole = in_array('editingteacher', $roleslower, true)
            || in_array('teacher', $roleslower, true)
            || in_array('lecturer', $roleslower, true);
        $teachingsections = unit_helper::get_my_teaching_sections($userid);
        $hasteachingsections = !empty($teachingsections);
        $isinstructor = $hasteacherrole || $hasteachingsections;

        $hascoursestudentrole = student_profile_helper::has_course_student_role($userid);
        $hasstudentprofile = $DB->record_exists('local_btiportal_student_profile', ['userid' => $userid]);
        $isstudent = $hascoursestudentrole || $hasstudentprofile;

        if ($issiteadmin || $isuniversityadmin || in_array('manager', $roleslower, true)) {
            $portalrole = 'admin';
        } else if ($isinstructor) {
            $portalrole = 'instructor';
        } else {
            $portalrole = 'student';
        }

        return [
            'portalrole' => $portalrole,
            'issiteadmin' => $issiteadmin,
            'isuniversityadmin' => $isuniversityadmin,
            'isdepartmentleader' => $isdepartmentleader,
            'canmanageacademic' => $canmanageacademic,
            'canviewacademic' => $canviewacademic,
            'roles' => $roles,
            'isinstructor' => $isinstructor,
            'isstudent' => $isstudent,
            'hasteachingsections' => $hasteachingsections,
            'hasstudentprofile' => $hasstudentprofile,
            'hascoursestudentrole' => $hascoursestudentrole,
        ];
    }
}
