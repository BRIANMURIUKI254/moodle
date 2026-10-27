<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Staff-only Moodle messaging contacts. Identity remains Moodle users.
 * Students are never returned here — they keep core_message_search_contacts.
 */
class staff_messaging_helper {
    /**
     * @return list<array>
     */
    public static function list_contacts(int $actorid, string $query = ''): array {
        global $DB;

        if ($actorid <= 0 || !isloggedin() || isguestuser($actorid)) {
            return [];
        }

        $query = trim($query);
        $staffids = self::staff_user_ids();
        unset($staffids[$actorid]);
        if (!$staffids) {
            return [];
        }

        $allowed = self::allowed_recipient_ids($actorid, $staffids);
        if (!$allowed) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal(array_values($allowed), SQL_PARAMS_NAMED, 'u');
        $where = "deleted = 0 AND suspended = 0 AND id {$insql}";
        if ($query !== '') {
            $where .= " AND (" . $DB->sql_like('firstname', ':q1', false, false)
                . " OR " . $DB->sql_like('lastname', ':q2', false, false)
                . " OR " . $DB->sql_like('email', ':q3', false, false)
                . " OR " . $DB->sql_like('username', ':q4', false, false) . ")";
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
        }

        $rows = $DB->get_records_select(
            'user',
            $where,
            $params,
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, email, username, picture, imagealt',
            0,
            40
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)$row->id,
                'fullname' => fullname($row),
                'email' => (string)$row->email,
                'username' => (string)$row->username,
                'staffrole' => self::staff_role_label((int)$row->id),
            ];
        }
        return $out;
    }

    public static function can_message(int $fromid, int $toid): bool {
        if ($fromid <= 0 || $toid <= 0 || $fromid === $toid) {
            return false;
        }
        $staffids = self::staff_user_ids();
        if (!isset($staffids[$fromid]) || !isset($staffids[$toid])) {
            return false;
        }
        $allowed = self::allowed_recipient_ids($fromid, $staffids);
        return isset($allowed[$toid]);
    }

    /**
     * @param array<int,int> $staffids
     * @return array<int,int>
     */
    private static function allowed_recipient_ids(int $actorid, array $staffids): array {
        if (department_auth_helper::is_university_admin($actorid)) {
            return $staffids;
        }

        $leaderdepts = self::leadership_department_ids($actorid);
        $lecturerdepts = self::lecturer_department_ids($actorid);
        $out = [];

        foreach ($staffids as $userid) {
            if (department_auth_helper::is_university_admin($userid)) {
                $out[$userid] = $userid;
                continue;
            }
            if ($leaderdepts) {
                $theirdepts = self::lecturer_department_ids($userid);
                if (array_intersect($leaderdepts, $theirdepts)) {
                    $out[$userid] = $userid;
                }
                continue;
            }
            if ($lecturerdepts) {
                $theirleaderdepts = self::leadership_department_ids($userid);
                if (array_intersect($lecturerdepts, $theirleaderdepts)) {
                    $out[$userid] = $userid;
                }
            }
        }
        return $out;
    }

    /**
     * @return array<int,int>
     */
    private static function staff_user_ids(): array {
        global $DB;
        $ids = [];

        $admins = get_admins();
        foreach ($admins as $admin) {
            $ids[(int)$admin->id] = (int)$admin->id;
        }

        if ($DB->get_manager()->table_exists('local_btiportal_lecturer_dept')) {
            $rows = $DB->get_records_select(
                'local_btiportal_lecturer_dept',
                "status = 'active'",
                [],
                '',
                'id, userid'
            );
            foreach ($rows as $row) {
                $ids[(int)$row->userid] = (int)$row->userid;
            }
        }

        if ($DB->get_manager()->table_exists('local_btiportal_staff_org')) {
            $rows = $DB->get_records_select(
                'local_btiportal_staff_org',
                "status = 'active'",
                [],
                '',
                'id, userid'
            );
            foreach ($rows as $row) {
                $ids[(int)$row->userid] = (int)$row->userid;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function lecturer_department_ids(int $userid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_btiportal_lecturer_dept')) {
            return [];
        }
        $rows = $DB->get_records('local_btiportal_lecturer_dept', [
            'userid' => $userid,
            'status' => 'active',
        ], '', 'id, departmentid');
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row->departmentid;
        }
        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function leadership_department_ids(int $userid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_btiportal_lecturer_dept')) {
            return [];
        }
        $rows = $DB->get_records_select(
            'local_btiportal_lecturer_dept',
            "userid = :userid AND status = 'active' AND (isdepartmentleader = 1 OR role IN ('head', 'deputy_head'))",
            ['userid' => $userid],
            '',
            'id, departmentid'
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row->departmentid;
        }
        return $ids;
    }

    private static function staff_role_label(int $userid): string {
        if (department_auth_helper::is_university_admin($userid)) {
            return 'admin';
        }
        if (self::leadership_department_ids($userid)) {
            return 'department_leader';
        }
        if (self::lecturer_department_ids($userid)) {
            return 'lecturer';
        }
        return 'staff';
    }

    public static function contact_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'userid'),
            'fullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
            'email' => new \core_external\external_value(PARAM_TEXT, 'email'),
            'username' => new \core_external\external_value(PARAM_TEXT, 'username'),
            'staffrole' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'staff role'),
        ];
    }
}
