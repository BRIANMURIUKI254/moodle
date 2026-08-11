<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Lecturer <-> Department membership.
 *
 * Uses existing Moodle users; no separate identity store. At most one active
 * primary department per user is enforced inside a transaction.
 *
 * Department Leader status is department-specific (isdepartmentleader) and must
 * only be granted by university academic managers — never self-assigned.
 */
class lecturer_department_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function assign(array $input, int $userid = 0): array {
        global $DB;

        $targetuserid = (int)($input['userid'] ?? 0);
        $departmentid = (int)($input['departmentid'] ?? 0);
        $membershiprole = strtolower(trim((string)($input['membershiprole'] ?? 'member')));
        $isprimary = !empty($input['isprimary']) ? 1 : 0;
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $isdepartmentleader = !empty($input['isdepartmentleader']) ? 1 : 0;
        $timestart = (int)($input['timestart'] ?? time());
        $timeend = (int)($input['timeend'] ?? 0);

        // Leadership via membershiprole head/deputy_head also sets the flag.
        if (in_array($membershiprole, department_auth_helper::LEADERSHIP_ROLES, true)) {
            $isdepartmentleader = 1;
        }

        $errors = self::validate($targetuserid, $departmentid, $membershiprole, $status, $userid, $isdepartmentleader);
        if (!$errors && $DB->record_exists('local_btiportal_lecturer_dept', [
            'userid' => $targetuserid,
            'departmentid' => $departmentid,
        ])) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Lecturer is already assigned to this department'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($isprimary && $status === 'active') {
                self::clear_primary_flags($targetuserid, null);
            }
            $id = $DB->insert_record('local_btiportal_lecturer_dept', (object)[
                'userid' => $targetuserid,
                'departmentid' => $departmentid,
                'membershiprole' => $membershiprole,
                'isprimary' => $isprimary,
                'isdepartmentleader' => $isdepartmentleader,
                'timestart' => $timestart > 0 ? $timestart : $now,
                'timeend' => $timeend,
                'status' => $status,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        $record = self::get($id);
        if ($isdepartmentleader) {
            audit_helper::log([
                'action' => 'department_leader_assignment',
                'entitytype' => 'lecturer_department',
                'entityid' => $id,
                'departmentid' => $departmentid,
                'after' => $record,
                'sourcemodule' => 'lecturer_department',
            ], $userid);
        }

        return ['ok' => true, 'record' => $record];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input, int $userid = 0): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_lecturer_dept', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Assignment not found']]];
        }

        $membershiprole = array_key_exists('membershiprole', $input)
            ? strtolower(trim((string)$input['membershiprole'])) : (string)$row->membershiprole;
        $status = array_key_exists('status', $input)
            ? strtolower(trim((string)$input['status'])) : (string)$row->status;
        $isprimary = array_key_exists('isprimary', $input)
            ? (!empty($input['isprimary']) ? 1 : 0) : (int)$row->isprimary;
        $prevleader = (int)($row->isdepartmentleader ?? 0);
        if (array_key_exists('isdepartmentleader', $input)) {
            $isdepartmentleader = !empty($input['isdepartmentleader']) ? 1 : 0;
        } else {
            $isdepartmentleader = $prevleader;
        }
        if (in_array($membershiprole, department_auth_helper::LEADERSHIP_ROLES, true) && $isdepartmentleader === 0
            && !array_key_exists('isdepartmentleader', $input)) {
            // Changing role to head implies leadership unless explicitly cleared.
            $isdepartmentleader = 1;
        }
        if (array_key_exists('membershiprole', $input)
            && !in_array($membershiprole, department_auth_helper::LEADERSHIP_ROLES, true)
            && !array_key_exists('isdepartmentleader', $input)
            && in_array((string)$row->membershiprole, department_auth_helper::LEADERSHIP_ROLES, true)) {
            // Demoting from head without explicit flag clears leadership.
            $isdepartmentleader = 0;
        }

        $timestart = array_key_exists('timestart', $input) ? (int)$input['timestart'] : (int)($row->timestart ?? 0);
        $timeend = array_key_exists('timeend', $input) ? (int)$input['timeend'] : (int)($row->timeend ?? 0);

        $errors = [];
        if (!in_array($membershiprole, academic_constants::MEMBERSHIP_ROLES, true)) {
            $errors[] = ['field' => 'membershiprole', 'message' => 'Invalid membership role'];
        }
        if (!in_array($status, academic_constants::ORG_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
        }
        if ($isdepartmentleader && $status === 'active') {
            $leadererr = self::validate_leadership_grant($userid, (int)$row->userid, (int)$row->departmentid);
            if ($leadererr) {
                $errors = array_merge($errors, $leadererr);
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $before = self::format($row);
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($isprimary && $status === 'active') {
                self::clear_primary_flags((int)$row->userid, $id);
            }
            $row->membershiprole = $membershiprole;
            $row->isprimary = ($status === 'active') ? $isprimary : 0;
            $row->isdepartmentleader = ($status === 'active') ? $isdepartmentleader : 0;
            $row->timestart = $timestart;
            $row->timeend = $timeend;
            $row->status = $status;
            $row->timemodified = time();
            $row->usermodified = $userid;
            $DB->update_record('local_btiportal_lecturer_dept', $row);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        $record = self::format($row);
        if ($prevleader === 0 && $isdepartmentleader === 1) {
            audit_helper::log([
                'action' => 'department_leader_assignment',
                'entitytype' => 'lecturer_department',
                'entityid' => $id,
                'departmentid' => (int)$row->departmentid,
                'before' => $before,
                'after' => $record,
                'sourcemodule' => 'lecturer_department',
            ], $userid);
        } else if ($prevleader === 1 && $isdepartmentleader === 0) {
            audit_helper::log([
                'action' => 'department_leader_removal',
                'entitytype' => 'lecturer_department',
                'entityid' => $id,
                'departmentid' => (int)$row->departmentid,
                'before' => $before,
                'after' => $record,
                'sourcemodule' => 'lecturer_department',
            ], $userid);
        }

        return ['ok' => true, 'record' => $record];
    }

    /**
     * Soft removal: keeps history, clears the primary and leadership flags.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function remove(int $id, int $userid = 0): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_lecturer_dept', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Assignment not found']]];
        }
        $before = self::format($row);
        $wasleader = (int)($row->isdepartmentleader ?? 0) === 1
            || in_array((string)$row->membershiprole, department_auth_helper::LEADERSHIP_ROLES, true);
        $row->status = 'inactive';
        $row->isprimary = 0;
        $row->isdepartmentleader = 0;
        if ((int)($row->timeend ?? 0) <= 0) {
            $row->timeend = time();
        }
        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_lecturer_dept', $row);
        $record = self::format($row);
        if ($wasleader) {
            audit_helper::log([
                'action' => 'department_leader_removal',
                'entitytype' => 'lecturer_department',
                'entityid' => $id,
                'departmentid' => (int)$row->departmentid,
                'before' => $before,
                'after' => $record,
                'sourcemodule' => 'lecturer_department',
            ], $userid);
        }
        return ['ok' => true, 'record' => $record];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_lecturer_dept', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list(?int $targetuserid = null, ?int $departmentid = null, ?string $status = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($targetuserid !== null && $targetuserid > 0) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $targetuserid;
        }
        if ($departmentid !== null && $departmentid > 0) {
            $where .= ' AND departmentid = :departmentid';
            $params['departmentid'] = $departmentid;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_lecturer_dept',
            $where,
            $params,
            'isprimary DESC, departmentid ASC, userid ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format($row);
        }
        return $out;
    }

    /**
     * @return list<array{field:string,message:string}>
     */
    private static function validate(
        int $targetuserid,
        int $departmentid,
        string $membershiprole,
        string $status,
        int $actorid,
        int $isdepartmentleader
    ): array {
        global $DB;
        $errors = [];
        if ($targetuserid <= 0 || !$DB->record_exists('user', ['id' => $targetuserid, 'deleted' => 0])) {
            $errors[] = ['field' => 'userid', 'message' => 'Moodle user not found'];
        }
        if (!department_helper::exists($departmentid)) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department not found'];
        } else if (!department_helper::exists_active($departmentid)) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department is not active'];
        }
        if (!in_array($membershiprole, academic_constants::MEMBERSHIP_ROLES, true)) {
            $errors[] = ['field' => 'membershiprole', 'message' => 'Invalid membership role'];
        }
        if (!in_array($status, academic_constants::ORG_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
        }
        if ($isdepartmentleader) {
            $errors = array_merge($errors, self::validate_leadership_grant($actorid, $targetuserid, $departmentid));
        }
        return $errors;
    }

    /**
     * Only university academic managers may grant Department Leader status.
     * A Department Leader must not self-assign or grant leadership to others.
     *
     * @return list<array{field:string,message:string}>
     */
    private static function validate_leadership_grant(int $actorid, int $targetuserid, int $departmentid): array {
        $errors = [];
        if ($actorid > 0 && $actorid === $targetuserid) {
            $errors[] = ['field' => 'isdepartmentleader', 'message' => 'A Department Leader cannot assign themselves'];
        }
        if ($actorid > 0 && !department_auth_helper::is_university_admin($actorid)) {
            $errors[] = [
                'field' => 'isdepartmentleader',
                'message' => 'Only an Admin or authorized academic manager may set Department Leader status',
            ];
        }
        if ($departmentid > 0 && !department_helper::exists_active($departmentid)) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department must be active for leadership assignment'];
        }
        return $errors;
    }

    private static function clear_primary_flags(int $targetuserid, ?int $excludeid): void {
        global $DB;
        $select = 'userid = ? AND isprimary = 1';
        $params = [$targetuserid];
        if ($excludeid !== null) {
            $select .= ' AND id <> ?';
            $params[] = $excludeid;
        }
        $DB->set_field_select('local_btiportal_lecturer_dept', 'isprimary', 0, $select, $params);
    }

    public static function format(\stdClass $row): array {
        global $DB;
        $isleader = (int)($row->isdepartmentleader ?? 0);
        if (!$isleader && in_array((string)$row->membershiprole, department_auth_helper::LEADERSHIP_ROLES, true)
            && (string)$row->status === 'active') {
            $isleader = 1;
        }
        $dept = $DB->get_record('local_btiportal_department', ['id' => (int)$row->departmentid], 'id,code,name,schoolid');
        return [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'departmentid' => (int)$row->departmentid,
            'departmentcode' => $dept ? (string)$dept->code : '',
            'departmentname' => $dept ? (string)$dept->name : '',
            'schoolid' => $dept ? (int)$dept->schoolid : 0,
            'membershiprole' => (string)$row->membershiprole,
            'isprimary' => (int)$row->isprimary,
            'isdepartmentleader' => $isleader,
            'timestart' => (int)($row->timestart ?? 0),
            'timeend' => (int)($row->timeend ?? 0),
            'status' => (string)$row->status,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)($row->usermodified ?? 0),
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'userid' => new \core_external\external_value(PARAM_INT, 'Moodle user id'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'departmentcode' => new \core_external\external_value(PARAM_TEXT, 'department code'),
            'departmentname' => new \core_external\external_value(PARAM_TEXT, 'department name'),
            'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
            'membershiprole' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'membershiprole'),
            'isprimary' => new \core_external\external_value(PARAM_INT, 'isprimary'),
            'isdepartmentleader' => new \core_external\external_value(PARAM_INT, 'isdepartmentleader'),
            'timestart' => new \core_external\external_value(PARAM_INT, 'timestart'),
            'timeend' => new \core_external\external_value(PARAM_INT, 'timeend'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }
}
