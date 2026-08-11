<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Department-scoped operational notifications (portal-managed).
 *
 * Delivery status from Moodle messaging is not invented — when unavailable the
 * UI should show that delivery status is not available from the current service.
 */
class operational_notification_helper {
    public const STATUSES = ['draft', 'scheduled', 'sent', 'failed', 'cancelled'];
    public const SCOPES = [
        'all_users', 'students', 'lecturers', 'staff',
        'school', 'department', 'programme', 'curriculum',
        'course_unit', 'moodle_course', 'selected_users',
    ];

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create(array $input, int $actorid = 0): array {
        global $DB;
        $parsed = self::parse($input, $actorid);
        if ($parsed['errors']) {
            return ['ok' => false, 'errors' => $parsed['errors']];
        }
        $data = $parsed['data'];
        $now = time();
        $data['timecreated'] = $now;
        $data['timemodified'] = $now;
        $data['usermodified'] = $actorid;
        $data['authorid'] = $actorid;
        $id = $DB->insert_record('local_btiportal_notification', (object)$data);
        $record = self::get($id);
        audit_helper::log([
            'action' => 'notification_create',
            'entitytype' => 'notification',
            'entityid' => $id,
            'departmentid' => (int)$data['departmentid'],
            'after' => $record,
            'sourcemodule' => 'notifications',
        ], $actorid);
        return ['ok' => true, 'record' => $record];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input, int $actorid = 0): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_notification', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Notification not found']]];
        }
        if (!self::can_manage_row($actorid, $row)) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Not authorised']]];
        }
        if (!in_array((string)$row->status, ['draft', 'scheduled'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Only draft or scheduled notifications can be edited']]];
        }
        $merged = array_merge(self::format($row), $input);
        $parsed = self::parse($merged, $actorid);
        if ($parsed['errors']) {
            return ['ok' => false, 'errors' => $parsed['errors']];
        }
        $before = self::format($row);
        foreach ($parsed['data'] as $k => $v) {
            $row->$k = $v;
        }
        $row->timemodified = time();
        $row->usermodified = $actorid;
        $DB->update_record('local_btiportal_notification', $row);
        $record = self::format($row);
        audit_helper::log([
            'action' => 'notification_update',
            'entitytype' => 'notification',
            'entityid' => $id,
            'departmentid' => (int)$row->departmentid,
            'before' => $before,
            'after' => $record,
            'sourcemodule' => 'notifications',
        ], $actorid);
        return ['ok' => true, 'record' => $record];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function cancel(int $id, int $actorid = 0): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_notification', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Notification not found']]];
        }
        if (!self::can_manage_row($actorid, $row)) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Not authorised']]];
        }
        if (!in_array((string)$row->status, ['draft', 'scheduled'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Only pending notifications can be cancelled']]];
        }
        $before = self::format($row);
        $row->status = 'cancelled';
        $row->timemodified = time();
        $row->usermodified = $actorid;
        $DB->update_record('local_btiportal_notification', $row);
        $record = self::format($row);
        audit_helper::log([
            'action' => 'notification_cancellation',
            'entitytype' => 'notification',
            'entityid' => $id,
            'departmentid' => (int)$row->departmentid,
            'before' => $before,
            'after' => $record,
            'sourcemodule' => 'notifications',
        ], $actorid);
        return ['ok' => true, 'record' => $record];
    }

    /**
     * Mark as sent/scheduled. Does not invent Moodle delivery receipts.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function send(int $id, int $actorid = 0, bool $scheduleonly = false): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_notification', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Notification not found']]];
        }
        if (!self::can_manage_row($actorid, $row)) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Not authorised']]];
        }
        if (!in_array((string)$row->status, ['draft', 'scheduled'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Notification cannot be sent in its current status']]];
        }
        $before = self::format($row);
        $now = time();
        if ($scheduleonly || ((int)$row->timescheduled > $now)) {
            $row->status = 'scheduled';
            $action = 'notification_schedule';
        } else {
            $row->status = 'sent';
            $row->timesent = $now;
            $row->deliverystatus = 'not_available';
            $action = 'notification_send';
        }
        $row->timemodified = $now;
        $row->usermodified = $actorid;
        $DB->update_record('local_btiportal_notification', $row);
        $record = self::format($row);
        audit_helper::log([
            'action' => $action,
            'entitytype' => 'notification',
            'entityid' => $id,
            'departmentid' => (int)$row->departmentid,
            'before' => $before,
            'after' => $record,
            'sourcemodule' => 'notifications',
        ], $actorid);
        return ['ok' => true, 'record' => $record];
    }

    /**
     * @return list<array>
     */
    public static function list(int $userid, array $filters = []): array {
        global $DB;
        $departmentid = isset($filters['departmentid']) ? (int)$filters['departmentid'] : null;
        $scope = department_auth_helper::resolve_scope($userid, $departmentid > 0 ? $departmentid : null);
        if (!$scope['ok']) {
            return [];
        }
        $params = [];
        $where = '1=1';
        if (!$scope['university']) {
            if (!$scope['departmentids']) {
                return [];
            }
            list($insql, $inparams) = $DB->get_in_or_equal($scope['departmentids'], SQL_PARAMS_NAMED, 'd');
            $where .= " AND departmentid {$insql}";
            $params = array_merge($params, $inparams);
        } else if ($departmentid > 0) {
            $where .= ' AND departmentid = :departmentid';
            $params['departmentid'] = $departmentid;
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = strtolower((string)$filters['status']);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_notification',
            $where,
            $params,
            'timemodified DESC, id DESC',
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

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_notification', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    private static function can_manage_row(int $actorid, \stdClass $row): bool {
        $scope = department_auth_helper::resolve_scope($actorid, (int)$row->departmentid ?: null);
        if (!$scope['ok']) {
            return false;
        }
        if ($scope['university']) {
            return true;
        }
        return in_array((int)$row->departmentid, $scope['departmentids'], true);
    }

    /**
     * @return array{errors:list,data?:array}
     */
    private static function parse(array $input, int $actorid): array {
        $errors = [];
        $title = trim((string)($input['title'] ?? ''));
        $body = trim((string)($input['body'] ?? ''));
        $scope = strtolower(trim((string)($input['scope'] ?? 'department')));
        $status = strtolower(trim((string)($input['status'] ?? 'draft')));
        $departmentid = (int)($input['departmentid'] ?? 0);

        if ($title === '') {
            $errors[] = ['field' => 'title', 'message' => 'Title is required'];
        }
        if ($body === '') {
            $errors[] = ['field' => 'body', 'message' => 'Body is required'];
        }
        if (!in_array($scope, self::SCOPES, true)) {
            $errors[] = ['field' => 'scope', 'message' => 'Invalid notification scope'];
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
        }

        $authscope = department_auth_helper::resolve_scope($actorid, $departmentid > 0 ? $departmentid : null);
        if (!$authscope['ok']) {
            return ['errors' => $authscope['errors']];
        }

        // Department Leaders may only use department-scoped targets.
        if (!$authscope['university']) {
            $allowedscopes = ['department', 'lecturers', 'students', 'programme', 'course_unit', 'moodle_course'];
            if (!in_array($scope, $allowedscopes, true)) {
                $errors[] = ['field' => 'scope', 'message' => 'Scope is not permitted for Department Leaders'];
            }
            if ($departmentid <= 0) {
                $errors[] = ['field' => 'departmentid', 'message' => 'Department is required'];
            } else if (!in_array($departmentid, $authscope['departmentids'], true)) {
                $errors[] = ['field' => 'departmentid', 'message' => 'Department is outside your authorized scope'];
            }
            // Reject arbitrary selected_users outside scope.
            if ($scope === 'selected_users') {
                $errors[] = ['field' => 'scope', 'message' => 'Selected user lists are not permitted for Department Leaders'];
            }
        } else if (in_array($scope, ['department', 'programme', 'course_unit', 'moodle_course'], true) && $departmentid <= 0) {
            // Soft: department recommended but school/all scopes ok without.
        }

        if ($departmentid > 0 && !department_helper::exists($departmentid)) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department not found'];
        }

        if ($errors) {
            return ['errors' => $errors];
        }

        $targetjson = (string)($input['targetjson'] ?? '');
        if ($targetjson !== '') {
            $decoded = json_decode($targetjson, true);
            if (!is_array($decoded)) {
                $errors[] = ['field' => 'targetjson', 'message' => 'Invalid target payload'];
                return ['errors' => $errors];
            }
        }

        return [
            'errors' => [],
            'data' => [
                'title' => substr($title, 0, 255),
                'body' => $body,
                'scope' => $scope,
                'departmentid' => $departmentid,
                'schoolid' => (int)($input['schoolid'] ?? 0),
                'programmeid' => (int)($input['programmeid'] ?? 0),
                'courseunitid' => (int)($input['courseunitid'] ?? 0),
                'moodlecourseid' => (int)($input['moodlecourseid'] ?? 0),
                'targetjson' => $targetjson,
                'status' => $status === 'sent' ? 'draft' : $status, // create never auto-sends
                'timescheduled' => (int)($input['timescheduled'] ?? 0),
                'timesent' => 0,
                'deliverystatus' => 'not_available',
                'deliverydetail' => 'Delivery status is not available from the current Moodle service.',
            ],
        ];
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'authorid' => (int)$row->authorid,
            'title' => (string)$row->title,
            'body' => (string)$row->body,
            'scope' => (string)$row->scope,
            'departmentid' => (int)$row->departmentid,
            'schoolid' => (int)$row->schoolid,
            'programmeid' => (int)$row->programmeid,
            'courseunitid' => (int)$row->courseunitid,
            'moodlecourseid' => (int)$row->moodlecourseid,
            'targetjson' => (string)($row->targetjson ?? ''),
            'status' => (string)$row->status,
            'timescheduled' => (int)$row->timescheduled,
            'timesent' => (int)$row->timesent,
            'deliverystatus' => (string)$row->deliverystatus,
            'deliverydetail' => (string)$row->deliverydetail,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)$row->usermodified,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'authorid' => new \core_external\external_value(PARAM_INT, 'authorid'),
            'title' => new \core_external\external_value(PARAM_TEXT, 'title'),
            'body' => new \core_external\external_value(PARAM_RAW, 'body'),
            'scope' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'scope'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'targetjson' => new \core_external\external_value(PARAM_RAW, 'targetjson'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timescheduled' => new \core_external\external_value(PARAM_INT, 'timescheduled'),
            'timesent' => new \core_external\external_value(PARAM_INT, 'timesent'),
            'deliverystatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'deliverystatus'),
            'deliverydetail' => new \core_external\external_value(PARAM_TEXT, 'deliverydetail'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }
}
