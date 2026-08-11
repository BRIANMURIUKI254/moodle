<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Department / university operational timetable entries.
 *
 * Reuses Moodle calendar events optionally (eventid) without duplicating identity.
 * Conflict detection classifies blocking / warning / informational results.
 */
class timetable_helper {
    public const STATUSES = ['draft', 'published', 'cancelled', 'archived'];
    public const DELIVERY_MODES = ['online', 'on_campus', 'hybrid'];
    public const CONFLICT_BLOCKING = 'blocking';
    public const CONFLICT_WARNING = 'warning';
    public const CONFLICT_INFO = 'informational';

    /**
     * @return array{ok:bool,record?:array,conflicts?:list,errors?:list}
     */
    public static function create(array $input, int $actorid = 0, bool $allowwarningoverride = false): array {
        global $DB;

        $parsed = self::parse_input($input);
        if ($parsed['errors']) {
            return ['ok' => false, 'errors' => $parsed['errors']];
        }
        $data = $parsed['data'];

        $scope = department_auth_helper::resolve_scope($actorid, (int)$data['departmentid']);
        if (!$scope['ok']) {
            return ['ok' => false, 'errors' => $scope['errors']];
        }
        if (!$scope['university'] && !in_array((int)$data['departmentid'], $scope['departmentids'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'departmentid', 'message' => 'Department is outside your authorized scope']]];
        }

        $conflicts = self::detect_conflicts($data, 0);
        $blocking = array_values(array_filter($conflicts, static fn($c) => $c['severity'] === self::CONFLICT_BLOCKING));
        if ($blocking) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Blocking timetable conflict']], 'conflicts' => $conflicts];
        }
        $warnings = array_values(array_filter($conflicts, static fn($c) => $c['severity'] === self::CONFLICT_WARNING));
        if ($warnings && !$allowwarningoverride) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Warning conflicts require override']], 'conflicts' => $conflicts];
        }
        if ($warnings && $allowwarningoverride && !department_auth_helper::is_university_admin($actorid)) {
            // Department Leaders may only override warnings inside their department.
            if (!$scope['university'] && !in_array((int)$data['departmentid'], $scope['departmentids'], true)) {
                return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Override not permitted outside your department']]];
            }
        }

        $now = time();
        $data['timecreated'] = $now;
        $data['timemodified'] = $now;
        $data['usermodified'] = $actorid;
        $data['overrideby'] = ($warnings && $allowwarningoverride) ? $actorid : 0;
        $data['overridetime'] = ($warnings && $allowwarningoverride) ? $now : 0;
        $data['overridereason'] = ($warnings && $allowwarningoverride)
            ? substr((string)($input['overridereason'] ?? ''), 0, 255) : '';

        $id = $DB->insert_record('local_btiportal_timetable', (object)$data);
        $record = self::get($id);

        if ($warnings && $allowwarningoverride) {
            audit_helper::log([
                'action' => 'timetable_conflict_override',
                'entitytype' => 'timetable',
                'entityid' => $id,
                'departmentid' => (int)$data['departmentid'],
                'after' => ['reason' => $data['overridereason'], 'conflicts' => $warnings],
                'sourcemodule' => 'timetable',
            ], $actorid);
        }

        audit_helper::log([
            'action' => 'timetable_create',
            'entitytype' => 'timetable',
            'entityid' => $id,
            'departmentid' => (int)$data['departmentid'],
            'after' => $record,
            'sourcemodule' => 'timetable',
        ], $actorid);

        return ['ok' => true, 'record' => $record, 'conflicts' => $conflicts];
    }

    /**
     * @return array{ok:bool,record?:array,conflicts?:list,errors?:list}
     */
    public static function update(int $id, array $input, int $actorid = 0, bool $allowwarningoverride = false): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_timetable', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Timetable entry not found']]];
        }

        $scope = department_auth_helper::resolve_scope($actorid, (int)$row->departmentid);
        if (!$scope['ok'] || (!$scope['university'] && !in_array((int)$row->departmentid, $scope['departmentids'], true))) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Not authorised for this timetable entry']]];
        }

        $merged = array_merge(self::format($row), $input);
        $parsed = self::parse_input($merged, true);
        if ($parsed['errors']) {
            return ['ok' => false, 'errors' => $parsed['errors']];
        }
        $data = $parsed['data'];

        // Department Leaders cannot reassign outside authorized departments.
        if (!$scope['university'] && (int)$data['departmentid'] !== (int)$row->departmentid) {
            if (!in_array((int)$data['departmentid'], $scope['departmentids'], true)) {
                return ['ok' => false, 'errors' => [['field' => 'departmentid', 'message' => 'Department is outside your authorized scope']]];
            }
        }

        $conflicts = self::detect_conflicts($data, $id);
        $blocking = array_values(array_filter($conflicts, static fn($c) => $c['severity'] === self::CONFLICT_BLOCKING));
        if ($blocking) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Blocking timetable conflict']], 'conflicts' => $conflicts];
        }
        $warnings = array_values(array_filter($conflicts, static fn($c) => $c['severity'] === self::CONFLICT_WARNING));
        if ($warnings && !$allowwarningoverride) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Warning conflicts require override']], 'conflicts' => $conflicts];
        }

        $before = self::format($row);
        foreach ($data as $k => $v) {
            $row->$k = $v;
        }
        $row->timemodified = time();
        $row->usermodified = $actorid;
        if ($warnings && $allowwarningoverride) {
            $row->overrideby = $actorid;
            $row->overridetime = time();
            $row->overridereason = substr((string)($input['overridereason'] ?? ''), 0, 255);
            audit_helper::log([
                'action' => 'timetable_conflict_override',
                'entitytype' => 'timetable',
                'entityid' => $id,
                'departmentid' => (int)$row->departmentid,
                'after' => ['reason' => $row->overridereason, 'conflicts' => $warnings],
                'sourcemodule' => 'timetable',
            ], $actorid);
        }
        $DB->update_record('local_btiportal_timetable', $row);
        $record = self::format($row);

        audit_helper::log([
            'action' => 'timetable_update',
            'entitytype' => 'timetable',
            'entityid' => $id,
            'departmentid' => (int)$row->departmentid,
            'before' => $before,
            'after' => $record,
            'sourcemodule' => 'timetable',
        ], $actorid);

        return ['ok' => true, 'record' => $record, 'conflicts' => $conflicts];
    }

    /**
     * Soft cancel — preserves history.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function cancel(int $id, int $actorid = 0): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_timetable', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Timetable entry not found']]];
        }
        $scope = department_auth_helper::resolve_scope($actorid, (int)$row->departmentid);
        if (!$scope['ok'] || (!$scope['university'] && !in_array((int)$row->departmentid, $scope['departmentids'], true))) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Not authorised']]];
        }
        $before = self::format($row);
        $row->status = 'cancelled';
        $row->timemodified = time();
        $row->usermodified = $actorid;
        $DB->update_record('local_btiportal_timetable', $row);
        $record = self::format($row);
        audit_helper::log([
            'action' => 'timetable_cancellation',
            'entitytype' => 'timetable',
            'entityid' => $id,
            'departmentid' => (int)$row->departmentid,
            'before' => $before,
            'after' => $record,
            'sourcemodule' => 'timetable',
        ], $actorid);
        return ['ok' => true, 'record' => $record];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_timetable', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    /**
     * Lecturer self-view: only sessions where lecturerid = the requesting lecturer.
     * Does not require Department Leader operational scope.
     *
     * @param array $filters Optional datestart, dateend, status, academicyearid, semesterid, courseunitid, moodlecourseid
     * @return list<array>
     */
    public static function list_for_lecturer(int $lecturerid, array $filters = []): array {
        global $DB;
        if ($lecturerid <= 0) {
            return [];
        }
        $params = ['lecturerid' => $lecturerid];
        $where = "status <> 'archived' AND lecturerid = :lecturerid";
        foreach (['courseunitid', 'moodlecourseid', 'academicyearid', 'semesterid'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND {$key} = :{$key}";
                $params[$key] = (int)$filters[$key];
            }
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = strtolower((string)$filters['status']);
        }
        if (!empty($filters['datestart'])) {
            $where .= ' AND sessiondate >= :datestart';
            $params['datestart'] = (int)$filters['datestart'];
        }
        if (!empty($filters['dateend'])) {
            $where .= ' AND sessiondate <= :dateend';
            $params['dateend'] = (int)$filters['dateend'];
        }
        $rows = $DB->get_records_select(
            'local_btiportal_timetable',
            $where,
            $params,
            'sessiondate ASC, starttime ASC, id ASC',
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
     * Institutional timetable sessions relevant to a student.
     *
     * Matches published (or filterable) sessions whose Moodle course or course unit
     * appears in the student's live enrolments / academic delivery plan.
     * Read-only — never used for create/update/cancel.
     *
     * @param array $filters Optional datestart, dateend, status, academicyearid, semesterid, courseunitid, moodlecourseid
     * @return list<array>
     */
    public static function list_for_student(int $studentid, array $filters = []): array {
        global $DB;
        if ($studentid <= 0) {
            return [];
        }

        $moodlecourseids = [];
        $courseunitids = [];

        // Live Moodle enrolments.
        $enrolments = enrolment_transition_helper::list_user_enrolments($studentid);
        foreach ($enrolments as $e) {
            $cid = (int)($e['courseid'] ?? 0);
            if ($cid > 0 && (int)($e['status'] ?? 1) === 0) {
                $moodlecourseids[$cid] = $cid;
            }
        }

        // Delivery plan expected units / mapped Moodle courses.
        $planresult = student_delivery_plan_helper::get_plan($studentid);
        if (!empty($planresult['ok']) && !empty($planresult['plan']['items'])) {
            foreach ($planresult['plan']['items'] as $item) {
                $cuid = (int)($item['courseunitid'] ?? 0);
                if ($cuid > 0) {
                    $courseunitids[$cuid] = $cuid;
                }
                $mcid = (int)($item['moodlecourseid'] ?? 0);
                if ($mcid > 0) {
                    $moodlecourseids[$mcid] = $mcid;
                }
            }
        }

        if (!$moodlecourseids && !$courseunitids) {
            return [];
        }

        $params = [];
        $where = "status <> 'archived' AND status <> 'cancelled'";
        $parts = [];
        if ($moodlecourseids) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_values($moodlecourseids), SQL_PARAMS_NAMED, 'mc');
            $parts[] = "moodlecourseid {$insql}";
            $params = array_merge($params, $inparams);
        }
        if ($courseunitids) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_values($courseunitids), SQL_PARAMS_NAMED, 'cu');
            $parts[] = "courseunitid {$insql}";
            $params = array_merge($params, $inparams);
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';

        foreach (['courseunitid', 'moodlecourseid', 'academicyearid', 'semesterid'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND {$key} = :{$key}";
                $params[$key] = (int)$filters[$key];
            }
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = strtolower((string)$filters['status']);
        }
        if (!empty($filters['datestart'])) {
            $where .= ' AND sessiondate >= :datestart';
            $params['datestart'] = (int)$filters['datestart'];
        }
        if (!empty($filters['dateend'])) {
            $where .= ' AND sessiondate <= :dateend';
            $params['dateend'] = (int)$filters['dateend'];
        }

        $rows = $DB->get_records_select(
            'local_btiportal_timetable',
            $where,
            $params,
            'sessiondate ASC, starttime ASC, id ASC',
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

    public static function list(int $userid, array $filters = []): array {
        global $DB;
        $departmentid = isset($filters['departmentid']) ? (int)$filters['departmentid'] : null;
        $scope = department_auth_helper::resolve_scope($userid, $departmentid > 0 ? $departmentid : null);
        if (!$scope['ok']) {
            return [];
        }

        $params = [];
        $where = "status <> 'archived'";
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

        foreach (['programmeid', 'courseunitid', 'moodlecourseid', 'lecturerid', 'academicyearid', 'semesterid'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND {$key} = :{$key}";
                $params[$key] = (int)$filters[$key];
            }
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = strtolower((string)$filters['status']);
        }
        if (!empty($filters['datestart'])) {
            $where .= ' AND sessiondate >= :datestart';
            $params['datestart'] = (int)$filters['datestart'];
        }
        if (!empty($filters['dateend'])) {
            $where .= ' AND sessiondate <= :dateend';
            $params['dateend'] = (int)$filters['dateend'];
        }

        $rows = $DB->get_records_select(
            'local_btiportal_timetable',
            $where,
            $params,
            'sessiondate ASC, starttime ASC, id ASC',
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
     * @param array $data
     * @return list<array{severity:string,type:string,message:string,entryid:int}>
     */
    public static function detect_conflicts(array $data, int $excludeid = 0): array {
        global $DB;
        $conflicts = [];

        $start = (int)$data['starttime'];
        $end = (int)$data['endtime'];
        if ($end <= $start) {
            $conflicts[] = [
                'severity' => self::CONFLICT_BLOCKING,
                'type' => 'invalid_time',
                'message' => 'End time must be after start time',
                'entryid' => 0,
            ];
            return $conflicts;
        }

        $sessiondate = (int)$data['sessiondate'];
        $daystart = $sessiondate;
        $dayend = $sessiondate + 86400 - 1;

        $params = [
            'daystart' => $daystart,
            'dayend' => $dayend,
            'start' => $start,
            'end' => $end,
        ];
        $where = "status IN ('draft','published')
            AND sessiondate BETWEEN :daystart AND :dayend
            AND starttime < :end AND endtime > :start";
        if ($excludeid > 0) {
            $where .= ' AND id <> :excludeid';
            $params['excludeid'] = $excludeid;
        }

        $candidates = $DB->get_records_select('local_btiportal_timetable', $where, $params);

        foreach ($candidates as $other) {
            if ((int)$data['lecturerid'] > 0 && (int)$other->lecturerid === (int)$data['lecturerid']) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_BLOCKING,
                    'type' => 'lecturer_overlap',
                    'message' => 'Lecturer has an overlapping timetable entry',
                    'entryid' => (int)$other->id,
                ];
            }
            if ((int)$data['courseunitid'] > 0 && (int)$other->courseunitid === (int)$data['courseunitid']) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_BLOCKING,
                    'type' => 'course_unit_overlap',
                    'message' => 'Course Unit has an overlapping timetable entry',
                    'entryid' => (int)$other->id,
                ];
            }
            if ((int)$data['moodlecourseid'] > 0 && (int)$other->moodlecourseid === (int)$data['moodlecourseid']) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_WARNING,
                    'type' => 'moodle_course_overlap',
                    'message' => 'Moodle Course has an overlapping timetable entry',
                    'entryid' => (int)$other->id,
                ];
            }
            $room = trim((string)($data['room'] ?? ''));
            $otherroom = trim((string)$other->room);
            if ($room !== '' && strcasecmp($room, $otherroom) === 0) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_WARNING,
                    'type' => 'room_overlap',
                    'message' => 'Room is already booked for this time',
                    'entryid' => (int)$other->id,
                ];
            }
            if ((int)$data['departmentid'] > 0 && (int)$other->departmentid === (int)$data['departmentid']
                && (int)$data['lecturerid'] === 0 && (int)$data['courseunitid'] === 0) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_INFO,
                    'type' => 'department_overlap',
                    'message' => 'Another department timetable entry overlaps this time',
                    'entryid' => (int)$other->id,
                ];
            }
        }

        // Academic period validity (informational when periods are set but inactive).
        if (!empty($data['academicyearid'])) {
            $year = $DB->get_record('local_btiportal_academic_year', ['id' => (int)$data['academicyearid']]);
            if (!$year) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_BLOCKING,
                    'type' => 'invalid_academic_year',
                    'message' => 'Academic year not found',
                    'entryid' => 0,
                ];
            } else if (!in_array((string)$year->status, ['active', 'upcoming'], true)) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_WARNING,
                    'type' => 'academic_period',
                    'message' => 'Academic year is not active or upcoming',
                    'entryid' => 0,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @return array{errors:list,data?:array}
     */
    private static function parse_input(array $input, bool $partial = false): array {
        $errors = [];
        $departmentid = (int)($input['departmentid'] ?? 0);
        if ($departmentid <= 0) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department is required'];
        } else if (!department_helper::exists($departmentid)) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department not found'];
        } else {
            $dept = department_helper::get($departmentid);
            if ($dept && ($dept['status'] ?? '') !== 'active') {
                $errors[] = ['field' => 'departmentid', 'message' => 'Department is not active'];
            }
        }

        $sessiondate = (int)($input['sessiondate'] ?? 0);
        $starttime = (int)($input['starttime'] ?? 0);
        $endtime = (int)($input['endtime'] ?? 0);
        if ($sessiondate <= 0) {
            $errors[] = ['field' => 'sessiondate', 'message' => 'Session date is required'];
        }
        if ($starttime <= 0 || $endtime <= 0) {
            $errors[] = ['field' => 'starttime', 'message' => 'Start and end times are required'];
        } else if ($endtime <= $starttime) {
            $errors[] = ['field' => 'endtime', 'message' => 'End time must be after start time'];
        }

        $status = strtolower(trim((string)($input['status'] ?? 'draft')));
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
        }
        $deliverymode = strtolower(trim((string)($input['deliverymode'] ?? 'on_campus')));
        if (!in_array($deliverymode, self::DELIVERY_MODES, true)) {
            $errors[] = ['field' => 'deliverymode', 'message' => 'Invalid delivery mode'];
        }

        if ($errors) {
            return ['errors' => $errors];
        }

        return [
            'errors' => [],
            'data' => [
                'departmentid' => $departmentid,
                'programmeid' => (int)($input['programmeid'] ?? 0),
                'courseunitid' => (int)($input['courseunitid'] ?? 0),
                'moodlecourseid' => (int)($input['moodlecourseid'] ?? 0),
                'lecturerid' => (int)($input['lecturerid'] ?? 0),
                'academicyearid' => (int)($input['academicyearid'] ?? 0),
                'semesterid' => (int)($input['semesterid'] ?? 0),
                'sessiondate' => $sessiondate,
                'starttime' => $starttime,
                'endtime' => $endtime,
                'room' => substr(trim((string)($input['room'] ?? '')), 0, 128),
                'deliverymode' => $deliverymode,
                'recurrence' => substr(trim((string)($input['recurrence'] ?? '')), 0, 64),
                'status' => $status,
                'notes' => (string)($input['notes'] ?? ''),
                'meetinglink' => substr(trim((string)($input['meetinglink'] ?? '')), 0, 512),
                'eventid' => (int)($input['eventid'] ?? 0),
                'overrideby' => (int)($input['overrideby'] ?? 0),
                'overridetime' => (int)($input['overridetime'] ?? 0),
                'overridereason' => substr((string)($input['overridereason'] ?? ''), 0, 255),
            ],
        ];
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'departmentid' => (int)$row->departmentid,
            'programmeid' => (int)$row->programmeid,
            'courseunitid' => (int)$row->courseunitid,
            'moodlecourseid' => (int)$row->moodlecourseid,
            'lecturerid' => (int)$row->lecturerid,
            'academicyearid' => (int)$row->academicyearid,
            'semesterid' => (int)$row->semesterid,
            'sessiondate' => (int)$row->sessiondate,
            'starttime' => (int)$row->starttime,
            'endtime' => (int)$row->endtime,
            'room' => (string)$row->room,
            'deliverymode' => (string)$row->deliverymode,
            'recurrence' => (string)$row->recurrence,
            'status' => (string)$row->status,
            'notes' => (string)$row->notes,
            'meetinglink' => isset($row->meetinglink) ? (string)$row->meetinglink : '',
            'eventid' => (int)$row->eventid,
            'overrideby' => (int)$row->overrideby,
            'overridetime' => (int)$row->overridetime,
            'overridereason' => (string)$row->overridereason,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)$row->usermodified,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'lecturerid' => new \core_external\external_value(PARAM_INT, 'lecturerid'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'sessiondate' => new \core_external\external_value(PARAM_INT, 'sessiondate unix day'),
            'starttime' => new \core_external\external_value(PARAM_INT, 'starttime'),
            'endtime' => new \core_external\external_value(PARAM_INT, 'endtime'),
            'room' => new \core_external\external_value(PARAM_TEXT, 'room'),
            'deliverymode' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'deliverymode'),
            'recurrence' => new \core_external\external_value(PARAM_TEXT, 'recurrence'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'notes' => new \core_external\external_value(PARAM_RAW, 'notes'),
            'meetinglink' => new \core_external\external_value(PARAM_RAW, 'meeting link'),
            'eventid' => new \core_external\external_value(PARAM_INT, 'moodle event id'),
            'overrideby' => new \core_external\external_value(PARAM_INT, 'overrideby'),
            'overridetime' => new \core_external\external_value(PARAM_INT, 'overridetime'),
            'overridereason' => new \core_external\external_value(PARAM_TEXT, 'overridereason'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }

    public static function conflict_structure_keys(): array {
        return [
            'severity' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'severity'),
            'type' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'type'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
            'entryid' => new \core_external\external_value(PARAM_INT, 'conflicting entry id'),
        ];
    }
}
