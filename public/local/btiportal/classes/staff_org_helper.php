<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Non-teaching staff placement in the school/department tree.
 *
 * Uses existing Moodle users; at most one active primary placement per user.
 */
class staff_org_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function assign(array $input, int $userid = 0): array {
        global $DB;

        $targetuserid = (int)($input['userid'] ?? 0);
        $schoolid = (int)($input['schoolid'] ?? 0);
        $departmentid = (int)($input['departmentid'] ?? 0);
        $isprimary = !empty($input['isprimary']) ? 1 : 0;
        $jobtitle = trim((string)($input['jobtitle'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'active')));

        $errors = self::validate($targetuserid, $schoolid, $departmentid, $status);
        if (!$errors && $DB->record_exists('local_btiportal_staff_org', [
            'userid' => $targetuserid,
            'departmentid' => $departmentid,
        ])) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Staff member is already assigned to this department'];
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
            $id = $DB->insert_record('local_btiportal_staff_org', (object)[
                'userid' => $targetuserid,
                'schoolid' => $schoolid,
                'departmentid' => $departmentid,
                'isprimary' => $isprimary,
                'jobtitle' => $jobtitle,
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

        return ['ok' => true, 'record' => self::get($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input, int $userid = 0): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_staff_org', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Assignment not found']]];
        }

        $schoolid = array_key_exists('schoolid', $input) ? (int)$input['schoolid'] : (int)$row->schoolid;
        $status = array_key_exists('status', $input)
            ? strtolower(trim((string)$input['status'])) : (string)$row->status;
        $isprimary = array_key_exists('isprimary', $input)
            ? (!empty($input['isprimary']) ? 1 : 0) : (int)$row->isprimary;

        $errors = [];
        if ($schoolid > 0 && !$DB->record_exists('local_btiportal_school', ['id' => $schoolid])) {
            $errors[] = ['field' => 'schoolid', 'message' => 'School not found'];
        }
        if (!in_array($status, academic_constants::ORG_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($isprimary && $status === 'active') {
                self::clear_primary_flags((int)$row->userid, $id);
            }
            $row->schoolid = $schoolid;
            if (array_key_exists('jobtitle', $input)) {
                $row->jobtitle = trim((string)$input['jobtitle']);
            }
            $row->isprimary = ($status === 'active') ? $isprimary : 0;
            $row->status = $status;
            $row->timemodified = time();
            $row->usermodified = $userid;
            $DB->update_record('local_btiportal_staff_org', $row);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Soft removal: keeps history, clears the primary flag.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function remove(int $id, int $userid = 0): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_staff_org', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Assignment not found']]];
        }
        $row->status = 'inactive';
        $row->isprimary = 0;
        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_staff_org', $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_staff_org', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list(
        ?int $targetuserid = null,
        ?int $departmentid = null,
        ?int $schoolid = null,
        ?string $status = null
    ): array {
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
        if ($schoolid !== null && $schoolid > 0) {
            $where .= ' AND schoolid = :schoolid';
            $params['schoolid'] = $schoolid;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_staff_org',
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
    private static function validate(int $targetuserid, int $schoolid, int $departmentid, string $status): array {
        global $DB;
        $errors = [];
        if ($targetuserid <= 0 || !$DB->record_exists('user', ['id' => $targetuserid, 'deleted' => 0])) {
            $errors[] = ['field' => 'userid', 'message' => 'Moodle user not found'];
        }
        if ($schoolid > 0 && !$DB->record_exists('local_btiportal_school', ['id' => $schoolid])) {
            $errors[] = ['field' => 'schoolid', 'message' => 'School not found'];
        }
        if ($departmentid > 0 && !department_helper::exists($departmentid)) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department not found'];
        }
        if ($schoolid <= 0 && $departmentid <= 0) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Provide a school or a department'];
        }
        if (!in_array($status, academic_constants::ORG_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
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
        $DB->set_field_select('local_btiportal_staff_org', 'isprimary', 0, $select, $params);
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'schoolid' => (int)$row->schoolid,
            'departmentid' => (int)$row->departmentid,
            'isprimary' => (int)$row->isprimary,
            'jobtitle' => (string)($row->jobtitle ?? ''),
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
            'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'isprimary' => new \core_external\external_value(PARAM_INT, 'isprimary'),
            'jobtitle' => new \core_external\external_value(PARAM_TEXT, 'jobtitle'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }
}
