<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Student course deferral / programme-change requests (admin-approved).
 * Payment is offline: student confirms payment contact, admin approves and syncs placement.
 */
class student_course_request_helper {
    public const TABLE = 'local_btiportal_course_request';
    public const TYPES = ['deferral', 'programme_change'];
    public const STATUSES = [
        'submitted',
        'awaiting_admin',
        'approved',
        'rejected',
        'cancelled',
    ];

    public static function tables_ready(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::TABLE);
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create_request(int $userid, array $input): array {
        global $DB, $USER;

        if (!self::tables_ready()) {
            return ['ok' => false, 'errors' => [['field' => 'schema', 'message' => 'Course request table not installed']]];
        }
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'User not found']]];
        }

        $requesttype = strtolower(trim((string)($input['requesttype'] ?? '')));
        $reason = trim((string)($input['reason'] ?? ''));
        $targetprogrammeid = (int)($input['targetprogrammeid'] ?? 0);
        $paymentconfirmed = !empty($input['paymentconfirmed']) ? 1 : 0;

        $errors = [];
        if (!in_array($requesttype, self::TYPES, true)) {
            $errors[] = ['field' => 'requesttype', 'message' => 'Invalid request type'];
        }
        if ($reason === '') {
            $errors[] = ['field' => 'reason', 'message' => 'Reason is required'];
        }
        if ($requesttype === 'programme_change' && $targetprogrammeid <= 0) {
            $errors[] = ['field' => 'targetprogrammeid', 'message' => 'Select the programme you want to apply for'];
        }
        if ($targetprogrammeid > 0 && !$DB->record_exists('local_btiportal_programme', ['id' => $targetprogrammeid])) {
            $errors[] = ['field' => 'targetprogrammeid', 'message' => 'Target programme not found'];
        }

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile) {
            $errors[] = ['field' => 'userid', 'message' => 'Academic profile not found — contact Academic Administration'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $open = $DB->record_exists_select(
            self::TABLE,
            "userid = :u AND status IN ('submitted', 'awaiting_admin')",
            ['u' => $userid]
        );
        if ($open) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'You already have an open course change request']]];
        }

        $now = time();
        $status = $paymentconfirmed ? 'awaiting_admin' : 'submitted';
        $id = $DB->insert_record(self::TABLE, (object)[
            'userid' => $userid,
            'requesttype' => $requesttype,
            'fromprogrammeid' => (int)($profile['programmeid'] ?? 0),
            'targetprogrammeid' => $targetprogrammeid,
            'reason' => $reason,
            'paymentconfirmed' => $paymentconfirmed,
            'status' => $status,
            'adminnotes' => '',
            'reviewedby' => 0,
            'timereviewed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => !empty($USER->id) ? (int)$USER->id : $userid,
        ]);

        return ['ok' => true, 'record' => self::get($id)];
    }

    public static function mark_payment_contacted(int $requestid, int $userid): array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $requestid]);
        if (!$row || (int)$row->userid !== $userid) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Request not found']]];
        }
        if (!in_array((string)$row->status, ['submitted', 'awaiting_admin'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Request cannot be updated']]];
        }
        $row->paymentconfirmed = 1;
        $row->status = 'awaiting_admin';
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    public static function cancel_request(int $requestid, int $userid): array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $requestid]);
        if (!$row || (int)$row->userid !== $userid) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Request not found']]];
        }
        if (!in_array((string)$row->status, ['submitted', 'awaiting_admin'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Request cannot be cancelled']]];
        }
        $row->status = 'cancelled';
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Admin approve: run deferral or programme change, sync student placement.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function approve(int $requestid, int $actorid, string $adminnotes = ''): array {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['id' => $requestid]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Request not found']]];
        }
        if ((string)$row->status !== 'awaiting_admin' && (string)$row->status !== 'submitted') {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Only open requests can be approved']]];
        }
        if (!(int)$row->paymentconfirmed) {
            return ['ok' => false, 'errors' => [['field' => 'paymentconfirmed', 'message' => 'Student has not confirmed payment / admin contact yet']]];
        }

        $userid = (int)$row->userid;
        $now = time();
        if ((string)$row->requesttype === 'deferral') {
            $life = student_lifecycle_helper::defer([
                'userid' => $userid,
                'reason' => (string)$row->reason,
                'notes' => $adminnotes !== '' ? $adminnotes : 'Approved student deferral request #' . $requestid,
                'effectivedate' => $now,
                'expectedreturndate' => $now + (180 * 86400),
            ], $actorid);
            if (empty($life['ok'])) {
                return $life;
            }
        } else {
            $target = (int)$row->targetprogrammeid;
            $programme = $DB->get_record('local_btiportal_programme', ['id' => $target]);
            if (!$programme) {
                return ['ok' => false, 'errors' => [['field' => 'targetprogrammeid', 'message' => 'Target programme not found']]];
            }
            $departmentid = (int)$programme->departmentid;
            $schoolid = 0;
            if ($departmentid > 0) {
                $dept = $DB->get_record('local_btiportal_department', ['id' => $departmentid], 'schoolid');
                $schoolid = $dept ? (int)$dept->schoolid : 0;
            }
            $curriculum = $DB->get_record_sql(
                "SELECT * FROM {local_btiportal_curriculum}
                  WHERE programmeid = :p AND status = 'active'
               ORDER BY effectivefrom DESC, id DESC",
                ['p' => $target],
                IGNORE_MULTIPLE
            );
            if (!$curriculum) {
                $curriculum = $DB->get_record_sql(
                    "SELECT * FROM {local_btiportal_curriculum}
                      WHERE programmeid = :p
                   ORDER BY id DESC",
                    ['p' => $target],
                    IGNORE_MULTIPLE
                );
            }
            if (!$curriculum) {
                return ['ok' => false, 'errors' => [['field' => 'curriculumid', 'message' => 'Target programme has no curriculum — create one before approving']]];
            }
            $life = student_lifecycle_helper::change_programme([
                'userid' => $userid,
                'schoolid' => $schoolid,
                'departmentid' => $departmentid,
                'programmeid' => $target,
                'curriculumid' => (int)$curriculum->id,
                'reason' => (string)$row->reason,
                'notes' => $adminnotes !== '' ? $adminnotes : 'Approved programme change request #' . $requestid,
                'effectivedate' => $now,
            ], $actorid);
            if (empty($life['ok'])) {
                return $life;
            }
        }

        $row->status = 'approved';
        $row->adminnotes = $adminnotes;
        $row->reviewedby = $actorid;
        $row->timereviewed = time();
        $row->timemodified = time();
        $row->usermodified = $actorid;
        $DB->update_record(self::TABLE, $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    public static function reject(int $requestid, int $actorid, string $adminnotes = ''): array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $requestid]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Request not found']]];
        }
        if (!in_array((string)$row->status, ['submitted', 'awaiting_admin'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Request cannot be rejected']]];
        }
        $row->status = 'rejected';
        $row->adminnotes = $adminnotes !== '' ? $adminnotes : 'Rejected by Academic Administration';
        $row->reviewedby = $actorid;
        $row->timereviewed = time();
        $row->timemodified = time();
        $row->usermodified = $actorid;
        $DB->update_record(self::TABLE, $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    public static function list_for_user(int $userid): array {
        global $DB;
        if (!self::tables_ready() || $userid <= 0) {
            return [];
        }
        $rows = $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated DESC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format($row);
        }
        return $out;
    }

    public static function list_all(string $status = ''): array {
        global $DB;
        if (!self::tables_ready()) {
            return [];
        }
        $params = [];
        $where = '1=1';
        if ($status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        $rows = $DB->get_records_select(self::TABLE, $where, $params, 'timemodified DESC', '*', 0, 200);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format($row);
        }
        return $out;
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    public static function format(\stdClass $row): array {
        global $DB;
        $fromname = '';
        $targetname = '';
        if ((int)$row->fromprogrammeid > 0) {
            $p = $DB->get_record('local_btiportal_programme', ['id' => (int)$row->fromprogrammeid], 'name');
            $fromname = $p ? (string)$p->name : '';
        }
        if ((int)$row->targetprogrammeid > 0) {
            $p = $DB->get_record('local_btiportal_programme', ['id' => (int)$row->targetprogrammeid], 'name');
            $targetname = $p ? (string)$p->name : '';
        }
        $user = $DB->get_record('user', ['id' => (int)$row->userid], 'id, firstname, lastname, email, idnumber');
        return [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'fullname' => $user ? trim($user->firstname . ' ' . $user->lastname) : '',
            'email' => $user ? (string)$user->email : '',
            'idnumber' => $user ? (string)$user->idnumber : '',
            'requesttype' => (string)$row->requesttype,
            'fromprogrammeid' => (int)$row->fromprogrammeid,
            'fromprogrammename' => $fromname,
            'targetprogrammeid' => (int)$row->targetprogrammeid,
            'targetprogrammename' => $targetname,
            'reason' => (string)$row->reason,
            'paymentconfirmed' => (int)$row->paymentconfirmed,
            'status' => (string)$row->status,
            'adminnotes' => (string)$row->adminnotes,
            'reviewedby' => (int)$row->reviewedby,
            'timereviewed' => (int)$row->timereviewed,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'fullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
            'email' => new \core_external\external_value(PARAM_TEXT, 'email'),
            'idnumber' => new \core_external\external_value(PARAM_TEXT, 'idnumber'),
            'requesttype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requesttype'),
            'fromprogrammeid' => new \core_external\external_value(PARAM_INT, 'fromprogrammeid'),
            'fromprogrammename' => new \core_external\external_value(PARAM_TEXT, 'fromprogrammename'),
            'targetprogrammeid' => new \core_external\external_value(PARAM_INT, 'targetprogrammeid'),
            'targetprogrammename' => new \core_external\external_value(PARAM_TEXT, 'targetprogrammename'),
            'reason' => new \core_external\external_value(PARAM_TEXT, 'reason'),
            'paymentconfirmed' => new \core_external\external_value(PARAM_INT, 'paymentconfirmed'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'adminnotes' => new \core_external\external_value(PARAM_TEXT, 'adminnotes'),
            'reviewedby' => new \core_external\external_value(PARAM_INT, 'reviewedby'),
            'timereviewed' => new \core_external\external_value(PARAM_INT, 'timereviewed'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
        ];
    }
}
