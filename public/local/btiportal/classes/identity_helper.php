<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * University identity uniqueness helpers — one email / username / idnumber → one Moodle user.
 */
class identity_helper {
    /**
     * Find non-deleted Moodle users matching any of the provided identity fields.
     *
     * @param string $email
     * @param string $username
     * @param string $idnumber Admission or staff/employee number stored as user.idnumber
     * @return array<int, array> keyed by userid
     */
    public static function lookup(string $email = '', string $username = '', string $idnumber = ''): array {
        global $DB;

        $email = trim(\core_text::strtolower($email));
        $username = trim(\core_text::strtolower($username));
        $idnumber = trim($idnumber);

        $matches = [];

        if ($email !== '') {
            $rows = $DB->get_records_select(
                'user',
                'deleted = 0 AND ' . $DB->sql_equal('email', ':email', false),
                ['email' => $email],
                'id ASC',
                'id, username, firstname, lastname, email, idnumber, suspended, phone1'
            );
            foreach ($rows as $row) {
                $matches[(int)$row->id] = self::enrich_user($row, 'email');
            }
        }

        if ($username !== '') {
            $row = $DB->get_record_select(
                'user',
                'deleted = 0 AND ' . $DB->sql_equal('username', ':username', false),
                ['username' => $username],
                'id, username, firstname, lastname, email, idnumber, suspended, phone1'
            );
            if ($row) {
                $uid = (int)$row->id;
                if (isset($matches[$uid])) {
                    $matches[$uid]['matchfields'][] = 'username';
                    $matches[$uid]['matchfields'] = array_values(array_unique($matches[$uid]['matchfields']));
                } else {
                    $matches[$uid] = self::enrich_user($row, 'username');
                }
            }
        }

        if ($idnumber !== '') {
            $rows = $DB->get_records_select(
                'user',
                "deleted = 0 AND idnumber <> '' AND " . $DB->sql_equal('idnumber', ':idnumber', false),
                ['idnumber' => $idnumber],
                'id ASC',
                'id, username, firstname, lastname, email, idnumber, suspended, phone1'
            );
            foreach ($rows as $row) {
                $uid = (int)$row->id;
                if (isset($matches[$uid])) {
                    $matches[$uid]['matchfields'][] = 'idnumber';
                    $matches[$uid]['matchfields'] = array_values(array_unique($matches[$uid]['matchfields']));
                } else {
                    $matches[$uid] = self::enrich_user($row, 'idnumber');
                }
            }
        }

        return array_values($matches);
    }

    /**
     * @param \stdClass $row
     * @param string $matchfield
     * @return array
     */
    protected static function enrich_user(\stdClass $row, string $matchfield): array {
        global $DB;

        $userid = (int)$row->id;
        $fullname = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
        $profile = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid], '*', IGNORE_MISSING);

        $programmename = '';
        $departmentname = '';
        $schoolname = '';
        if ($profile && !empty($profile->programmeid)) {
            $programmename = (string)$DB->get_field('local_btiportal_programme', 'name', ['id' => (int)$profile->programmeid]) ?: '';
        }
        if ($profile && !empty($profile->departmentid)) {
            $departmentname = (string)$DB->get_field('local_btiportal_department', 'name', ['id' => (int)$profile->departmentid]) ?: '';
        }
        if ($profile && !empty($profile->schoolid)) {
            $schoolname = (string)$DB->get_field('local_btiportal_school', 'name', ['id' => (int)$profile->schoolid]) ?: '';
        }

        return [
            'userid' => $userid,
            'username' => (string)$row->username,
            'firstname' => (string)$row->firstname,
            'lastname' => (string)$row->lastname,
            'fullname' => $fullname !== '' ? $fullname : (string)$row->username,
            'email' => (string)$row->email,
            'idnumber' => (string)$row->idnumber,
            'phone1' => (string)($row->phone1 ?? ''),
            'suspended' => !empty($row->suspended),
            'matchfields' => [$matchfield],
            'hasstudentprofile' => !empty($profile),
            'admissionstatus' => $profile ? (string)($profile->admissionstatus ?? '') : '',
            'academicstatus' => $profile ? (string)($profile->academicstatus ?? '') : '',
            'programmeid' => $profile ? (int)($profile->programmeid ?? 0) : 0,
            'programmename' => $programmename,
            'departmentid' => $profile ? (int)($profile->departmentid ?? 0) : 0,
            'departmentname' => $departmentname,
            'schoolid' => $profile ? (int)($profile->schoolid ?? 0) : 0,
            'schoolname' => $schoolname,
            'forcepasswordchange' => (bool) get_user_preferences('auth_forcepasswordchange', false, $userid),
        ];
    }

    /**
     * Ensure Moodle auth_forcepasswordchange is set for a newly provisioned account.
     */
    public static function ensure_force_password_change(int $userid): void {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);
        set_user_preference('auth_forcepasswordchange', 1, $user);
    }
}
