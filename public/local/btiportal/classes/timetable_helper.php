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
    /** Canonical weekday codes stored in recurrence (comma-separated). */
    public const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * Normalize recurrence into "Mon,Wed,Fri". Empty string = legacy single-date row.
     */
    public static function normalize_weekdays(string $recurrence): string {
        if (trim($recurrence) === '') {
            return '';
        }
        $aliases = [
            'mon' => 'Mon', 'monday' => 'Mon',
            'tue' => 'Tue', 'tues' => 'Tue', 'tuesday' => 'Tue',
            'wed' => 'Wed', 'wednesday' => 'Wed',
            'thu' => 'Thu', 'thur' => 'Thu', 'thurs' => 'Thu', 'thursday' => 'Thu',
            'fri' => 'Fri', 'friday' => 'Fri',
            'sat' => 'Sat', 'saturday' => 'Sat',
            'sun' => 'Sun', 'sunday' => 'Sun',
        ];
        $parts = preg_split('/[,\s|;]+/', trim($recurrence)) ?: [];
        $seen = [];
        foreach ($parts as $part) {
            $key = strtolower(trim($part));
            if ($key === '' || !isset($aliases[$key])) {
                continue;
            }
            $seen[$aliases[$key]] = true;
        }
        $ordered = [];
        foreach (self::WEEKDAYS as $day) {
            if (!empty($seen[$day])) {
                $ordered[] = $day;
            }
        }
        return implode(',', $ordered);
    }

    /**
     * @return list<string>
     */
    public static function parse_weekdays(string $recurrence): array {
        $normalized = self::normalize_weekdays($recurrence);
        if ($normalized === '') {
            return [];
        }
        return explode(',', $normalized);
    }

    /** Weekday code from a unix timestamp in server local timezone. */
    public static function weekday_from_unix(int $unix): string {
        $map = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $idx = (int)date('w', $unix);
        return $map[$idx] ?? 'Mon';
    }

    /** Seconds since local midnight for overlap checks across recurring rules. */
    public static function time_of_day_seconds(int $unix): int {
        $local = (int)date('H', $unix) * 3600 + (int)date('i', $unix) * 60 + (int)date('s', $unix);
        return $local;
    }

    /**
     * Date-range filter that keeps weekday-recurring rules visible for calendar expansion.
     * Legacy single-date rows (empty recurrence) still filter by sessiondate.
     *
     * @param array $filters
     * @param array $params
     */
    protected static function append_date_range_filter(string &$where, array &$params, array $filters): void {
        $hasstart = !empty($filters['datestart']);
        $hasend = !empty($filters['dateend']);
        if (!$hasstart && !$hasend) {
            return;
        }
        $parts = ["(TRIM(COALESCE(recurrence, '')) <> '')"];
        if ($hasstart && $hasend) {
            $parts[] = "(TRIM(COALESCE(recurrence, '')) = '' AND sessiondate BETWEEN :datestart AND :dateend)";
            $params['datestart'] = (int)$filters['datestart'];
            $params['dateend'] = (int)$filters['dateend'];
        } else if ($hasstart) {
            $parts[] = "(TRIM(COALESCE(recurrence, '')) = '' AND sessiondate >= :datestart)";
            $params['datestart'] = (int)$filters['datestart'];
        } else {
            $parts[] = "(TRIM(COALESCE(recurrence, '')) = '' AND sessiondate <= :dateend)";
            $params['dateend'] = (int)$filters['dateend'];
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
    }

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
        self::attach_zoom_for_session($id);
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
        self::attach_zoom_for_session($id);
        $record = self::get($id);

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
        self::deactivate_zoom_if_unused($row);
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
        self::append_date_range_filter($where, $params, $filters);
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
        $where = "status = 'published'";
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

        foreach (['courseunitid', 'moodlecourseid'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND {$key} = :{$key}";
                $params[$key] = (int)$filters[$key];
            }
        }
        // Period filters: match the requested period OR entries left unscoped (0).
        foreach (['academicyearid', 'semesterid'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND ({$key} = :{$key} OR {$key} = 0)";
                $params[$key] = (int)$filters[$key];
            }
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = strtolower((string)$filters['status']);
        }
        self::append_date_range_filter($where, $params, $filters);

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
        self::append_date_range_filter($where, $params, $filters);

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

        $mydays = self::parse_weekdays((string)($data['recurrence'] ?? ''));
        if (!$mydays) {
            $sessiondate = (int)$data['sessiondate'];
            if ($sessiondate > 0) {
                $mydays = [self::weekday_from_unix($sessiondate)];
            }
        }
        $mystarttod = self::time_of_day_seconds($start);
        $myendtod = self::time_of_day_seconds($end);

        $params = [];
        $where = "status IN ('draft','published')";
        if ($excludeid > 0) {
            $where .= ' AND id <> :excludeid';
            $params['excludeid'] = $excludeid;
        }

        $candidates = $DB->get_records_select(
            'local_btiportal_timetable',
            $where,
            $params,
            'id ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );

        foreach ($candidates as $other) {
            $otherdays = self::parse_weekdays((string)$other->recurrence);
            if (!$otherdays) {
                $otherdays = [self::weekday_from_unix((int)$other->sessiondate)];
            }
            if ($mydays && !array_intersect($mydays, $otherdays)) {
                continue;
            }

            $otherstarttod = self::time_of_day_seconds((int)$other->starttime);
            $otherendtod = self::time_of_day_seconds((int)$other->endtime);
            if (!($mystarttod < $otherendtod && $myendtod > $otherstarttod)) {
                continue;
            }

            if ((int)$data['lecturerid'] > 0 && (int)$other->lecturerid === (int)$data['lecturerid']) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_BLOCKING,
                    'type' => 'lecturer_overlap',
                    'message' => 'Lecturer has an overlapping timetable entry on a shared weekday',
                    'entryid' => (int)$other->id,
                ];
            }
            if ((int)$data['courseunitid'] > 0 && (int)$other->courseunitid === (int)$data['courseunitid']) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_BLOCKING,
                    'type' => 'course_unit_overlap',
                    'message' => 'Course Unit has an overlapping timetable entry on a shared weekday',
                    'entryid' => (int)$other->id,
                ];
            }
            if ((int)$data['moodlecourseid'] > 0 && (int)$other->moodlecourseid === (int)$data['moodlecourseid']) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_WARNING,
                    'type' => 'moodle_course_overlap',
                    'message' => 'Moodle Course has an overlapping timetable entry on a shared weekday',
                    'entryid' => (int)$other->id,
                ];
            }
            $room = trim((string)($data['room'] ?? ''));
            $otherroom = trim((string)$other->room);
            if ($room !== '' && strcasecmp($room, $otherroom) === 0) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_WARNING,
                    'type' => 'room_overlap',
                    'message' => 'Room is already booked for this time on a shared weekday',
                    'entryid' => (int)$other->id,
                ];
            }
            if ((int)$data['departmentid'] > 0 && (int)$other->departmentid === (int)$data['departmentid']
                && (int)$data['lecturerid'] === 0 && (int)$data['courseunitid'] === 0) {
                $conflicts[] = [
                    'severity' => self::CONFLICT_INFO,
                    'type' => 'department_overlap',
                    'message' => 'Another department timetable entry overlaps this time on a shared weekday',
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

        $recurrence = self::normalize_weekdays((string)($input['recurrence'] ?? ''));
        $weekdays = self::parse_weekdays($recurrence);
        $sessiondate = (int)($input['sessiondate'] ?? 0);
        $starttime = (int)($input['starttime'] ?? 0);
        $endtime = (int)($input['endtime'] ?? 0);

        if ($weekdays) {
            // Recurring weekday rule: sessiondate is an anchor / valid-from day (defaults to today).
            if ($sessiondate <= 0) {
                $sessiondate = (int)strtotime('today');
            }
        } else if ($sessiondate <= 0) {
            $errors[] = ['field' => 'recurrence', 'message' => 'Select at least one day of the week'];
        }

        if ($starttime <= 0 || $endtime <= 0) {
            $errors[] = ['field' => 'starttime', 'message' => 'Start and end times are required'];
        } else if (self::time_of_day_seconds($endtime) <= self::time_of_day_seconds($starttime)) {
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

        $moodlecourseid = (int)($input['moodlecourseid'] ?? 0);
        if ($moodlecourseid > 0) {
            global $DB;
            if (!$DB->record_exists('course', ['id' => $moodlecourseid])) {
                $errors[] = ['field' => 'moodlecourseid', 'message' => 'Moodle course not found'];
            }
        }

        $courseunitid = (int)($input['courseunitid'] ?? 0);
        if ($courseunitid > 0) {
            global $DB;
            if ($DB->get_manager()->table_exists('local_btiportal_course_unit')
                && !$DB->record_exists('local_btiportal_course_unit', ['id' => $courseunitid])) {
                $errors[] = ['field' => 'courseunitid', 'message' => 'Course Unit not found'];
            }
        }

        if ($errors) {
            return ['errors' => $errors];
        }

        return [
            'errors' => [],
            'data' => [
                'departmentid' => $departmentid,
                'programmeid' => (int)($input['programmeid'] ?? 0),
                'courseunitid' => $courseunitid,
                'moodlecourseid' => $moodlecourseid,
                'lecturerid' => (int)($input['lecturerid'] ?? 0),
                'academicyearid' => (int)($input['academicyearid'] ?? 0),
                'semesterid' => (int)($input['semesterid'] ?? 0),
                'sessiondate' => $sessiondate,
                'starttime' => $starttime,
                'endtime' => $endtime,
                'room' => substr(trim((string)($input['room'] ?? '')), 0, 128),
                'deliverymode' => $deliverymode,
                'recurrence' => substr($recurrence, 0, 64),
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
        global $DB;

        $lecturerid = (int)$row->lecturerid;
        $lecturername = '';
        if ($lecturerid > 0) {
            $user = $DB->get_record('user', ['id' => $lecturerid, 'deleted' => 0], 'id, firstname, lastname', IGNORE_MISSING);
            if ($user) {
                $lecturername = fullname($user);
            }
        } else if ((int)$row->courseunitid > 0
            && $DB->get_manager()->table_exists('local_btiportal_course_unit_teacher')) {
            // Fall back to the primary teacher assigned to this course unit.
            $teacher = $DB->get_record_sql(
                "SELECT u.id, u.firstname, u.lastname
                   FROM {local_btiportal_course_unit_teacher} cut
                   JOIN {user} u ON u.id = cut.userid AND u.deleted = 0
                  WHERE cut.courseunitid = :cuid AND cut.isprimary = 1
               ORDER BY cut.id ASC",
                ['cuid' => (int)$row->courseunitid],
                IGNORE_MULTIPLE
            );
            if ($teacher) {
                $lecturerid = (int)$teacher->id;
                $lecturername = fullname($teacher);
            }
        }

        return [
            'id' => (int)$row->id,
            'departmentid' => (int)$row->departmentid,
            'programmeid' => (int)$row->programmeid,
            'courseunitid' => (int)$row->courseunitid,
            'moodlecourseid' => (int)$row->moodlecourseid,
            'lecturerid' => $lecturerid,
            'lecturername' => $lecturername,
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
            'zoommeetingid' => isset($row->zoommeetingid) ? (string)$row->zoommeetingid : '',
            'zoomcmid' => isset($row->zoomcmid) ? (int)$row->zoomcmid : 0,
            'zoomstatus' => isset($row->zoomstatus) && (string)$row->zoomstatus !== ''
                ? (string)$row->zoomstatus
                : (isset($row->meetinglink) && trim((string)$row->meetinglink) !== '' ? 'linked' : 'none'),
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
            'lecturername' => new \core_external\external_value(PARAM_TEXT, 'lecturer display name', VALUE_OPTIONAL),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'sessiondate' => new \core_external\external_value(PARAM_INT, 'sessiondate unix day'),
            'starttime' => new \core_external\external_value(PARAM_INT, 'starttime'),
            'endtime' => new \core_external\external_value(PARAM_INT, 'endtime'),
            'room' => new \core_external\external_value(PARAM_TEXT, 'room'),
            'deliverymode' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'deliverymode'),
            'recurrence' => new \core_external\external_value(PARAM_TEXT, 'weekday recurrence e.g. Mon,Wed,Fri'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'notes' => new \core_external\external_value(PARAM_RAW, 'notes'),
            'meetinglink' => new \core_external\external_value(PARAM_RAW, 'meeting link'),
            'zoommeetingid' => new \core_external\external_value(PARAM_TEXT, 'zoom meeting id', VALUE_OPTIONAL),
            'zoomcmid' => new \core_external\external_value(PARAM_INT, 'zoom course module id', VALUE_OPTIONAL),
            'zoomstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'linked|unavailable|not_configured|none', VALUE_OPTIONAL),
            'eventid' => new \core_external\external_value(PARAM_INT, 'moodle event id'),
            'overrideby' => new \core_external\external_value(PARAM_INT, 'overrideby'),
            'overridetime' => new \core_external\external_value(PARAM_INT, 'overridetime'),
            'overridereason' => new \core_external\external_value(PARAM_TEXT, 'overridereason'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }

    /**
     * Generate or reuse a Zoom meeting for the session's course unit. Never fakes a URL.
     */
    private static function attach_zoom_for_session(int $id): void {
        global $DB;

        $row = $DB->get_record('local_btiportal_timetable', ['id' => $id]);
        if (!$row) {
            return;
        }

        $dbman = $DB->get_manager();
        if (!$dbman->field_exists('local_btiportal_timetable', 'zoomstatus')) {
            return;
        }

        $existinglink = trim((string)($row->meetinglink ?? ''));
        if ($existinglink !== '' && !empty($row->zoommeetingid)) {
            $row->zoomstatus = 'linked';
            $DB->update_record('local_btiportal_timetable', $row);
            return;
        }

        $courseid = (int)$row->moodlecourseid;
        $sectionid = 0;
        if ((int)$row->courseunitid > 0 && $dbman->table_exists('local_btiportal_course_unit')) {
            $unit = $DB->get_record('local_btiportal_course_unit', ['id' => (int)$row->courseunitid], 'id,courseid,sectionid,code,name');
            if ($unit) {
                if ($courseid <= 1) {
                    $courseid = (int)$unit->courseid;
                    $row->moodlecourseid = $courseid;
                }
                $sectionid = (int)$unit->sectionid;
            }
        }

        if ($courseid <= 1 || $sectionid <= 0) {
            if ($existinglink === '') {
                $row->zoomstatus = 'none';
                $DB->update_record('local_btiportal_timetable', $row);
            }
            return;
        }

        if (!class_exists('\\local_btizoomapi\\zoom_link_helper') || !$DB->record_exists('modules', ['name' => 'zoom'])) {
            $row->zoomstatus = 'not_configured';
            $DB->update_record('local_btiportal_timetable', $row);
            return;
        }

        try {
            $displayname = '';
            if (!empty($unit)) {
                $displayname = trim((string)$unit->code . ' ' . (string)$unit->name);
            }
            $zoom = \local_btizoomapi\zoom_link_helper::ensure($courseid, $sectionid, $displayname);
            $joinurl = trim((string)($zoom['joinurl'] ?? ''));
            if ($joinurl === '') {
                $row->zoomstatus = 'unavailable';
                $DB->update_record('local_btiportal_timetable', $row);
                return;
            }
            $row->meetinglink = substr($joinurl, 0, 512);
            $row->zoomcmid = (int)($zoom['cmid'] ?? 0);
            $row->zoomstatus = 'linked';
            if ($row->zoomcmid > 0 && $DB->get_manager()->table_exists('zoom')) {
                $cm = $DB->get_record('course_modules', ['id' => $row->zoomcmid], 'id,instance');
                if ($cm) {
                    $meetingid = $DB->get_field('zoom', 'meeting_id', ['id' => (int)$cm->instance]);
                    $row->zoommeetingid = $meetingid !== false ? (string)$meetingid : '';
                }
            }
            $DB->update_record('local_btiportal_timetable', $row);
        } catch (\Throwable $e) {
            $row->zoomstatus = 'unavailable';
            $DB->update_record('local_btiportal_timetable', $row);
            debugging('Timetable Zoom attach failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Hide the Zoom activity when no other live sessions still use it.
     */
    private static function deactivate_zoom_if_unused(\stdClass $row): void {
        global $DB, $CFG;

        $cmid = isset($row->zoomcmid) ? (int)$row->zoomcmid : 0;
        if ($cmid <= 0) {
            return;
        }
        $others = $DB->count_records_select(
            'local_btiportal_timetable',
            "id <> :id AND zoomcmid = :cmid AND status IN ('draft', 'published')",
            ['id' => (int)$row->id, 'cmid' => $cmid]
        );
        if ($others > 0) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        try {
            set_coursemodule_visible($cmid, 0);
        } catch (\Throwable $e) {
            debugging('Could not hide Zoom activity after timetable cancel: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
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
