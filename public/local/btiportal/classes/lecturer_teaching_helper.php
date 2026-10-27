<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/enrollib.php');

/**
 * Lecturer teaching assignments across Course Units and Moodle Courses.
 *
 * One Moodle user may have many department, course-unit, and Moodle course assignments.
 * Does not create Moodle users or duplicate Moodle courses.
 */
class lecturer_teaching_helper {
    /**
     * List academic course-unit teaching assignments for a lecturer.
     *
     * @return list<array>
     */
    public static function list_course_units(int $userid, ?string $status = 'active'): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }

        $params = ['userid' => $userid];
        $where = 't.userid = :userid';
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND t.status = :status';
            $params['status'] = strtolower($status);
        } else {
            $where .= " AND t.status <> 'removed'";
        }

        $sql = "SELECT t.*, cu.code AS unitcode, cu.name AS unitname, cu.programmeid,
                       cu.curriculumid, cu.yearofstudy, cu.semestersequence, cu.courseid AS moodlecourseid,
                       cu.sectionid AS moodlesectionid, cu.status AS unitstatus, p.departmentid
                  FROM {local_btiportal_course_unit_teacher} t
                  JOIN {local_btiportal_course_unit} cu ON cu.id = t.courseunitid
             LEFT JOIN {local_btiportal_programme} p ON p.id = cu.programmeid
                 WHERE {$where}
              ORDER BY t.status ASC, cu.code ASC, t.id ASC";

        $rows = $DB->get_records_sql($sql, $params, 0, academic_constants::LIST_LIMIT);
        $teaching = self::list_moodle_teaching_courses($userid);
        $teachingbycourse = [];
        foreach ($teaching as $course) {
            $teachingbycourse[(int)$course['courseid']] = $course;
        }
        $studentcounts = self::student_counts_for_courses(array_map(
            static fn($row) => (int)$row->moodlecourseid,
            $rows
        ));
        $out = [];
        foreach ($rows as $row) {
            $yearid = (int)($row->academicyearid ?? 0);
            $semid = (int)($row->semesterid ?? 0);
            $courseid = (int)$row->moodlecourseid;
            $out[] = [
                'id' => (int)$row->id,
                'courseunitid' => (int)$row->courseunitid,
                'userid' => (int)$row->userid,
                'teachingrole' => (string)$row->teachingrole,
                'isprimary' => (int)$row->isprimary,
                'status' => (string)$row->status,
                'academicyearid' => $yearid,
                'semesterid' => $semid,
                'islegacyunperioded' => ($yearid === 0 && $semid === 0) ? 1 : 0,
                'assignedby' => (int)$row->assignedby,
                'timeassigned' => (int)$row->timeassigned,
                'timeremoved' => (int)$row->timeremoved,
                'unitcode' => (string)$row->unitcode,
                'unitname' => (string)$row->unitname,
                'programmeid' => (int)$row->programmeid,
                'departmentid' => (int)($row->departmentid ?? 0),
                'curriculumid' => (int)$row->curriculumid,
                'yearofstudy' => (int)$row->yearofstudy,
                'semestersequence' => (int)$row->semestersequence,
                'moodlecourseid' => $courseid,
                'sectionid' => (int)($row->moodlesectionid ?? 0),
                'unitstatus' => (string)$row->unitstatus,
                'islegacy' => 0,
                'moodlecourseshortname' => (string)($teachingbycourse[$courseid]['shortname'] ?? ''),
                'moodleenrolmentstatus' => self::enrolment_status_for_unit($courseid, $teachingbycourse),
                'moodleenrolmentlabel' => self::enrolment_label_for_unit($courseid, $teachingbycourse),
                'moodleteachingrole' => (string)($teachingbycourse[$courseid]['role'] ?? ''),
                'studentcount' => (int)($studentcounts[$courseid] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Enrol every active assigned lecturer onto their mapped Moodle course.
     * Reuses the existing Moodle user and existing enrolments.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function reconcile_moodle_enrolments(int $userid = 0): array {
        global $DB;

        $params = [];
        $where = "t.status = 'active'";
        if ($userid > 0) {
            $where .= ' AND t.userid = :userid';
            $params['userid'] = $userid;
        }
        $sql = "SELECT t.id, t.userid, t.courseunitid, cu.code AS unitcode, cu.name AS unitname, cu.courseid
                  FROM {local_btiportal_course_unit_teacher} t
                  JOIN {local_btiportal_course_unit} cu ON cu.id = t.courseunitid
                 WHERE {$where}
              ORDER BY cu.code ASC, t.id ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, academic_constants::LIST_LIMIT);

        $items = [];
        $repaired = 0;
        $connected = 0;
        $unmapped = 0;
        $failed = 0;
        $teachingcache = [];
        foreach ($rows as $row) {
            $courseid = (int)$row->courseid;
            $lecturerid = (int)$row->userid;
            if (!isset($teachingcache[$lecturerid])) {
                $bycourse = [];
                foreach (self::list_moodle_teaching_courses($lecturerid) as $course) {
                    $bycourse[(int)$course['courseid']] = $course;
                }
                $teachingcache[$lecturerid] = $bycourse;
            }
            $bycourse = $teachingcache[$lecturerid];
            $status = self::enrolment_status_for_unit($courseid, $bycourse);
            $action = 'none';
            $message = self::enrolment_label_for_unit($courseid, $bycourse);
            $currentrole = (string)($bycourse[$courseid]['role'] ?? '');
            $needsediting = $status === 'missing'
                || ($status === 'connected' && $currentrole !== 'editingteacher');
            if ($needsediting && $courseid > 1) {
                $assign = self::ensure_editing_teacher_for_assigned_course($lecturerid, $courseid);
                if (!empty($assign['ok'])) {
                    $status = 'connected';
                    $action = $currentrole === 'teacher' ? 'promoted' : 'synchronized';
                    $message = 'Connected';
                    $repaired++;
                    $teachingcache[$lecturerid][$courseid] = $assign['record'] ?? [
                        'courseid' => $courseid,
                        'statuslabel' => 'active',
                        'status' => 0,
                        'role' => 'editingteacher',
                    ];
                } else if ($status === 'missing') {
                    $status = 'failed';
                    $action = 'failed';
                    $message = (string)($assign['errors'][0]['message'] ?? 'Could not enrol lecturer');
                    $failed++;
                } else {
                    $connected++;
                }
            } else if ($status === 'connected') {
                $connected++;
            } else {
                $unmapped++;
            }
            $items[] = [
                'assignmentid' => (int)$row->id,
                'userid' => $lecturerid,
                'courseunitid' => (int)$row->courseunitid,
                'unitcode' => (string)$row->unitcode,
                'unitname' => (string)$row->unitname,
                'moodlecourseid' => $courseid,
                'status' => $status,
                'label' => $message,
                'action' => $action,
            ];
        }

        return [
            'ok' => true,
            'record' => [
                'userid' => $userid,
                'items' => $items,
                'connected' => $connected,
                'repaired' => $repaired,
                'unmapped' => $unmapped,
                'failed' => $failed,
                'count' => count($items),
            ],
        ];
    }

    private static function enrolment_status_for_unit(int $courseid, array $teachingbycourse): string {
        if ($courseid <= 1) {
            return 'unmapped';
        }
        $row = $teachingbycourse[$courseid] ?? null;
        if ($row && (($row['statuslabel'] ?? '') === 'active' || (int)($row['status'] ?? 1) === 0)) {
            return 'connected';
        }
        return 'missing';
    }

    private static function enrolment_label_for_unit(int $courseid, array $teachingbycourse): string {
        $status = self::enrolment_status_for_unit($courseid, $teachingbycourse);
        if ($status === 'connected') {
            return 'Connected';
        }
        if ($status === 'unmapped') {
            return 'Course Unit is not mapped to Moodle.';
        }
        return 'Teaching assignment exists — Moodle enrolment missing';
    }

    /**
     * Live lecturer workload optionally filtered by academic period.
     *
     * Legacy unperioded assignments (academicyearid=0 AND semesterid=0) are reported
     * separately and excluded from selected-period totals unless $includelegacy is true.
     */
    public static function get_workload(
        int $userid,
        ?int $academicyearid = null,
        ?int $semesterid = null,
        bool $includelegacy = false
    ): array {
        global $DB;

        $departments = lecturer_department_helper::list($userid, null, 'active');
        $allunits = self::list_course_units($userid, 'active');
        $periodunits = [];
        $legacyunits = [];
        foreach ($allunits as $unit) {
            $y = (int)($unit['academicyearid'] ?? 0);
            $s = (int)($unit['semesterid'] ?? 0);
            $islegacy = ($y === 0 && $s === 0);
            if ($islegacy) {
                $legacyunits[] = $unit;
                if ($includelegacy || ($academicyearid === null && $semesterid === null)) {
                    // Only include legacy in unfiltered view by default.
                    if ($academicyearid === null && $semesterid === null) {
                        $periodunits[] = $unit;
                    } else if ($includelegacy) {
                        $periodunits[] = $unit;
                    }
                }
                continue;
            }
            if ($academicyearid !== null && $academicyearid > 0 && $y !== $academicyearid) {
                continue;
            }
            if ($semesterid !== null && $semesterid > 0 && $s !== $semesterid) {
                continue;
            }
            $periodunits[] = $unit;
        }

        $moodle = self::list_moodle_teaching_courses($userid);
        $primaryunits = 0;
        $colecunits = 0;
        foreach ($periodunits as $unit) {
            if ((int)$unit['isprimary'] === 1 || $unit['teachingrole'] === academic_constants::ROLE_PRIMARY_LECTURER) {
                $primaryunits++;
            } else {
                $colecunits++;
            }
        }

        $byyear = [];
        $bysemester = [];
        if ($academicyearid && $academicyearid > 0) {
            $year = $DB->get_record('local_btiportal_academic_year', ['id' => $academicyearid]);
            if ($year) {
                $byyear[] = [
                    'academicyearid' => (int)$year->id,
                    'name' => (string)$year->name,
                    'activecourseunits' => count($periodunits),
                    'activemoodlecourses' => count(array_filter($moodle, static fn($r) => $r['statuslabel'] === 'active')),
                ];
            }
        }
        if ($semesterid && $semesterid > 0) {
            $sem = $DB->get_record('local_btiportal_semester', ['id' => $semesterid]);
            if ($sem) {
                $bysemester[] = [
                    'semesterid' => (int)$sem->id,
                    'name' => (string)$sem->name,
                    'activecourseunits' => count($periodunits),
                    'activemoodlecourses' => count(array_filter($moodle, static fn($r) => $r['statuslabel'] === 'active')),
                ];
            }
        }

        $primarydept = null;
        foreach ($departments as $dept) {
            if ((int)$dept['isprimary'] === 1) {
                $primarydept = $dept;
                break;
            }
        }

        return [
            'userid' => $userid,
            'academicyearid' => (int)($academicyearid ?? 0),
            'semesterid' => (int)($semesterid ?? 0),
            'includelegacy' => $includelegacy ? 1 : 0,
            'departmentcount' => count($departments),
            'primarydepartmentid' => $primarydept ? (int)$primarydept['departmentid'] : 0,
            'activecourseunits' => count($periodunits),
            'primarycourseunits' => $primaryunits,
            'colecturercourseunits' => $colecunits,
            'activemoodlecourses' => count(array_filter($moodle, static fn($r) => $r['statuslabel'] === 'active')),
            'totalmoodleteaching' => count($moodle),
            'legacyunperiodedcount' => count($legacyunits),
            'byacademicyear' => $byyear,
            'bysemester' => $bysemester,
            'departments' => $departments,
            'courseunits' => $periodunits,
            'legacycourseunits' => $legacyunits,
            'moodlecourses' => $moodle,
        ];
    }

    /**
     * Moodle teaching enrolments (editingteacher / teacher) for a lecturer.
     *
     * @return list<array>
     */
    public static function list_moodle_teaching_courses(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }

        $courses = enrol_get_all_users_courses($userid, false);
        $out = [];
        foreach ($courses as $course) {
            $cid = (int)$course->id;
            if ($cid <= SITEID) {
                continue;
            }

            $context = \context_course::instance($cid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $roles = get_user_roles($context, $userid, false);
            $teachingroles = array_values(array_filter(
                array_map(static fn($role) => (string)$role->shortname, $roles),
                static fn($shortname) => in_array($shortname, academic_constants::MOODLE_TEACHING_ROLES, true)
            ));
            if (!$teachingroles) {
                continue;
            }

            $roleshortname = in_array('editingteacher', $teachingroles, true)
                ? 'editingteacher'
                : $teachingroles[0];
            $active = enrol_get_enrolment_end($cid, $userid) !== false;
            $enrolment = $DB->get_record_sql(
                "SELECT ue.id, ue.status, ue.timestart, ue.timeend
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE ue.userid = :userid AND e.courseid = :courseid
               ORDER BY ue.status ASC, e.status ASC, ue.id ASC",
                ['userid' => $userid, 'courseid' => $cid],
                IGNORE_MULTIPLE
            );

            $out[] = [
                'enrolmentid' => (int)($enrolment->id ?? 0),
                'courseid' => $cid,
                'fullname' => (string)$course->fullname,
                'shortname' => (string)$course->shortname,
                'visible' => (int)$course->visible,
                'role' => $roleshortname,
                'status' => $active ? ENROL_USER_ACTIVE : ENROL_USER_SUSPENDED,
                'statuslabel' => $active ? 'active' : 'inactive',
                'timestart' => (int)($enrolment->timestart ?? 0),
                'timeend' => (int)($enrolment->timeend ?? 0),
            ];
        }

        usort($out, static fn($a, $b) => strcasecmp($a['fullname'], $b['fullname']));
        return array_slice($out, 0, academic_constants::LIST_LIMIT);
    }

    /**
     * True when the user has an active Moodle teaching enrolment on the course.
     */
    public static function user_teaches_moodle_course(int $userid, int $courseid): bool {
        global $DB;
        if ($userid <= 0 || $courseid <= SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
            error_log(sprintf(
                '[local_btiportal] Course teaching authorization denied: user=%d course=%d reason=%s',
                $userid,
                $courseid,
                $userid <= 0 ? 'invalid_userid' : ($courseid <= SITEID ? 'invalid_courseid' : 'course_not_found')
            ));
            return false;
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        $roles = $context ? get_user_roles($context, $userid, false) : [];
        $allroles = array_values(array_unique(array_map(
            static fn($role) => (string)$role->shortname,
            $roles
        )));
        $teachingroles = array_values(array_unique(array_filter(
            $allroles,
            static fn($shortname) => in_array($shortname, academic_constants::MOODLE_TEACHING_ROLES, true)
        )));
        $activeenrolmentend = enrol_get_enrolment_end($courseid, $userid);
        if ($teachingroles && $activeenrolmentend !== false) {
            error_log(sprintf(
                '[local_btiportal] Course teaching authorization: outcome=authorized user=%d course=%d active_enrolment=1 course_roles=%s teaching_roles=%s active_until=%d',
                $userid,
                $courseid,
                $allroles ? implode(',', $allroles) : 'none',
                implode(',', $teachingroles),
                (int)$activeenrolmentend
            ));
            return true;
        }

        $rows = $DB->get_records_sql(
            "SELECT e.id AS enrolid, e.status AS enrolstatus,
                    ue.id AS enrolmentid, ue.status AS userenrolstatus, ue.timestart, ue.timeend
               FROM {enrol} e
          LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid
              WHERE e.courseid = :courseid
           ORDER BY e.id ASC",
            ['userid' => $userid, 'courseid' => $courseid]
        );
        $now = time();
        $diagnostics = [];
        $hasteachingrole = (bool)$teachingroles;
        foreach ($rows as $row) {
            $userenrolstatus = isset($row->userenrolstatus) ? (int)$row->userenrolstatus : null;
            $timestart = isset($row->timestart) ? (int)$row->timestart : null;
            $timeend = isset($row->timeend) ? (int)$row->timeend : null;
            $reason = 'authorized';
            if ((int)$row->enrolstatus !== ENROL_INSTANCE_ENABLED) {
                $reason = 'enrol_instance_disabled';
            } else if ($userenrolstatus === null) {
                $reason = 'no_user_enrolment';
            } else if ($userenrolstatus !== ENROL_USER_ACTIVE) {
                $reason = 'user_enrolment_inactive';
            } else if ($timestart !== null && $timestart > 0 && $timestart > $now) {
                $reason = 'user_enrolment_not_started';
            } else if ($timeend !== null && $timeend > 0 && $timeend <= $now) {
                $reason = 'user_enrolment_expired';
            } else if (!$hasteachingrole) {
                $reason = 'no_authorized_course_role';
            } else if ($activeenrolmentend === false) {
                $reason = 'inactive_by_moodle_enrolment_api';
            }
            $diagnostics[] = sprintf(
                'enrolid=%d,enrolstatus=%d,userenrolmentid=%d,userenrolstatus=%s,timestart=%s,timeend=%s,roles=%s,reason=%s',
                (int)$row->enrolid,
                (int)$row->enrolstatus,
                (int)($row->enrolmentid ?? 0),
                $userenrolstatus === null ? 'none' : (string)$userenrolstatus,
                $timestart === null ? 'none' : (string)$timestart,
                $timeend === null ? 'none' : (string)$timeend,
                $teachingroles ? implode(',', $teachingroles) : 'none',
                $reason
            );
        }

        $failure = !$rows ? 'no_enrolment_instance' : ($hasteachingrole
            ? 'no_active_user_enrolment'
            : 'no_authorized_course_role');
        error_log(sprintf(
            '[local_btiportal] Course teaching authorization denied: outcome=denied user=%d course=%d reason=%s active_enrolment=%d course_roles=%s teaching_roles=%s details=%s',
            $userid,
            $courseid,
            $failure,
            $activeenrolmentend === false ? 0 : 1,
            $allroles ? implode(',', $allroles) : 'none',
            $teachingroles ? implode(',', $teachingroles) : 'none',
            $diagnostics ? implode(';', $diagnostics) : 'no_rows'
        ));
        return false;
    }

    /**
     * Require an active Moodle teaching enrolment for the authenticated user.
     *
     * @throws \moodle_exception
     */
    public static function require_moodle_course_teacher(int $userid, int $courseid): void {
        if (!self::user_teaches_moodle_course($userid, $courseid)) {
            throw new \moodle_exception('notcourseteacher', 'local_btiportal');
        }
    }

    /**
     * True when the lecturer has an active Course Unit assignment mapped to this Moodle course.
     */
    public static function user_teaches_mapped_course(int $userid, int $courseid): bool {
        global $DB;
        if ($userid <= 0 || $courseid <= 1) {
            return false;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_btiportal_course_unit_teacher} t
               JOIN {local_btiportal_course_unit} cu ON cu.id = t.courseunitid
              WHERE t.userid = :userid
                AND t.status = 'active'
                AND cu.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );
    }

    /**
     * Enrol/assign Moodle editingteacher on a mapped course the lecturer is assigned to teach.
     * Course context only. Does not grant site administrator. Reuses existing enrolment.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function ensure_editing_teacher_for_assigned_course(int $userid, int $courseid): array {
        if (!self::user_teaches_mapped_course($userid, $courseid)) {
            return ['ok' => false, 'errors' => [[
                'field' => 'courseid',
                'message' => 'Not assigned to teach this Course Unit',
            ]]];
        }
        $result = self::assign_moodle_course($userid, $courseid, 'editingteacher');
        if (empty($result['ok'])) {
            return $result;
        }
        self::drop_non_editing_teacher_role($userid, $courseid);
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if ($context) {
            $context->mark_dirty();
        }
        if (function_exists('reload_all_capabilities')) {
            reload_all_capabilities();
        }
        if (!empty($result['record']) && is_array($result['record'])) {
            $result['record']['role'] = 'editingteacher';
        }
        return $result;
    }

    /**
     * Remove Moodle non-editing teacher when editingteacher is present.
     * Does not unenrol and does not touch student roles.
     */
    private static function drop_non_editing_teacher_role(int $userid, int $courseid): void {
        global $DB;
        $role = $DB->get_record('role', ['shortname' => 'teacher']);
        if (!$role) {
            return;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return;
        }
        role_unassign((int)$role->id, $userid, $context->id);
    }

    /**
     * Student enrolment counts keyed by Moodle course id.
     *
     * @param list<int> $courseids
     * @return array<int,int>
     */
    private static function student_counts_for_courses(array $courseids): array {
        global $DB;
        $unique = [];
        foreach ($courseids as $courseid) {
            $id = (int)$courseid;
            if ($id > 1) {
                $unique[$id] = $id;
            }
        }
        if (!$unique) {
            return [];
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', IGNORE_MISSING);
        if (!$studentrole) {
            return [];
        }
        $counts = [];
        foreach ($unique as $courseid) {
            $ctx = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$ctx) {
                $counts[$courseid] = 0;
                continue;
            }
            $counts[$courseid] = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT ra.userid)
                   FROM {role_assignments} ra
                   JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                  WHERE ra.contextid = ? AND ra.roleid = ?",
                [$ctx->id, (int)$studentrole->id]
            );
        }
        return $counts;
    }

    /**
     * Enrol / assign a lecturer to a Moodle course with editingteacher or teacher.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function assign_moodle_course(int $userid, int $courseid, string $roleshortname = 'editingteacher'): array {
        global $DB;

        $roleshortname = strtolower(trim($roleshortname));
        if (!in_array($roleshortname, academic_constants::MOODLE_TEACHING_ROLES, true)) {
            return ['ok' => false, 'errors' => [['field' => 'role', 'message' => 'Teaching role must be editingteacher or teacher']]];
        }
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Moodle course not found']]];
        }

        $role = $DB->get_record('role', ['shortname' => $roleshortname]);
        if (!$role) {
            return ['ok' => false, 'errors' => [['field' => 'role', 'message' => 'Moodle teaching role not configured']]];
        }

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'No manual enrolment instance on this Moodle course']]];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Manual enrolment plugin unavailable']]];
        }

        $plugin->enrol_user($instance, $userid, (int)$role->id, time(), 0, ENROL_USER_ACTIVE);

        $courses = self::list_moodle_teaching_courses($userid);
        $record = null;
        foreach ($courses as $course) {
            if ((int)$course['courseid'] === $courseid) {
                $record = $course;
                break;
            }
        }
        if (!$record) {
            $record = [
                'enrolmentid' => 0,
                'courseid' => $courseid,
                'fullname' => (string)$DB->get_field('course', 'fullname', ['id' => $courseid]),
                'shortname' => (string)$DB->get_field('course', 'shortname', ['id' => $courseid]),
                'visible' => (int)$DB->get_field('course', 'visible', ['id' => $courseid]),
                'role' => $roleshortname,
                'status' => 0,
                'statuslabel' => 'active',
                'timestart' => time(),
                'timeend' => 0,
            ];
        }
        return ['ok' => true, 'record' => $record];
    }

    /**
     * End a Moodle course teaching assignment (suspend manual enrolment). Does not delete the user.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function remove_moodle_course(int $userid, int $courseid, bool $unenrol = false): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'No manual enrolment instance on this Moodle course']]];
        }
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Manual enrolment plugin unavailable']]];
        }

        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if (!$ue) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Lecturer is not enrolled on this Moodle course']]];
        }

        if ($unenrol) {
            $plugin->unenrol_user($instance, $userid);
            $statuslabel = 'removed';
            $status = 1;
        } else {
            $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED, $ue->timestart, time());
            $statuslabel = 'inactive';
            $status = ENROL_USER_SUSPENDED;
        }

        return [
            'ok' => true,
            'record' => [
                'enrolmentid' => (int)$ue->id,
                'courseid' => $courseid,
                'fullname' => (string)$DB->get_field('course', 'fullname', ['id' => $courseid]),
                'shortname' => (string)$DB->get_field('course', 'shortname', ['id' => $courseid]),
                'visible' => (int)$DB->get_field('course', 'visible', ['id' => $courseid]),
                'role' => '',
                'status' => $status,
                'statuslabel' => $statuslabel,
                'timestart' => (int)$ue->timestart,
                'timeend' => time(),
            ],
        ];
    }

    public static function course_unit_assignment_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'teachingrole' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'teachingrole'),
            'isprimary' => new \core_external\external_value(PARAM_INT, 'isprimary'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'islegacyunperioded' => new \core_external\external_value(PARAM_INT, 'islegacyunperioded'),
            'assignedby' => new \core_external\external_value(PARAM_INT, 'assignedby'),
            'timeassigned' => new \core_external\external_value(PARAM_INT, 'timeassigned'),
            'timeremoved' => new \core_external\external_value(PARAM_INT, 'timeremoved'),
            'unitcode' => new \core_external\external_value(PARAM_TEXT, 'unitcode'),
            'unitname' => new \core_external\external_value(PARAM_TEXT, 'unitname'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy'),
            'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'sectionid' => new \core_external\external_value(PARAM_INT, 'Moodle course_sections.id', VALUE_OPTIONAL),
            'unitstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'unitstatus'),
            'islegacy' => new \core_external\external_value(PARAM_INT, 'islegacy'),
            'moodlecourseshortname' => new \core_external\external_value(PARAM_TEXT, 'moodlecourseshortname', VALUE_OPTIONAL),
            'moodleenrolmentstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'moodleenrolmentstatus', VALUE_OPTIONAL),
            'moodleenrolmentlabel' => new \core_external\external_value(PARAM_TEXT, 'moodleenrolmentlabel', VALUE_OPTIONAL),
            'moodleteachingrole' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'Moodle course role shortname', VALUE_OPTIONAL),
            'studentcount' => new \core_external\external_value(PARAM_INT, 'Enrolled Moodle students on the mapped course', VALUE_OPTIONAL),
        ];
    }

    public static function reconcile_item_structure_keys(): array {
        return [
            'assignmentid' => new \core_external\external_value(PARAM_INT, 'assignmentid'),
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'unitcode' => new \core_external\external_value(PARAM_TEXT, 'unitcode'),
            'unitname' => new \core_external\external_value(PARAM_TEXT, 'unitname'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'label' => new \core_external\external_value(PARAM_TEXT, 'label'),
            'action' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'action'),
        ];
    }

    public static function reconcile_structure_keys(): array {
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'items' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(self::reconcile_item_structure_keys())
            ),
            'connected' => new \core_external\external_value(PARAM_INT, 'connected'),
            'repaired' => new \core_external\external_value(PARAM_INT, 'repaired'),
            'unmapped' => new \core_external\external_value(PARAM_INT, 'unmapped'),
            'failed' => new \core_external\external_value(PARAM_INT, 'failed'),
            'count' => new \core_external\external_value(PARAM_INT, 'count'),
        ];
    }

    public static function moodle_teaching_structure_keys(): array {
        return [
            'enrolmentid' => new \core_external\external_value(PARAM_INT, 'enrolmentid'),
            'courseid' => new \core_external\external_value(PARAM_INT, 'courseid'),
            'fullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
            'shortname' => new \core_external\external_value(PARAM_TEXT, 'shortname'),
            'visible' => new \core_external\external_value(PARAM_INT, 'visible'),
            'role' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'role'),
            'status' => new \core_external\external_value(PARAM_INT, 'status'),
            'statuslabel' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'statuslabel'),
            'timestart' => new \core_external\external_value(PARAM_INT, 'timestart'),
            'timeend' => new \core_external\external_value(PARAM_INT, 'timeend'),
        ];
    }

    public static function workload_structure_keys(): array {
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'includelegacy' => new \core_external\external_value(PARAM_INT, 'includelegacy'),
            'departmentcount' => new \core_external\external_value(PARAM_INT, 'departmentcount'),
            'primarydepartmentid' => new \core_external\external_value(PARAM_INT, 'primarydepartmentid'),
            'activecourseunits' => new \core_external\external_value(PARAM_INT, 'activecourseunits'),
            'primarycourseunits' => new \core_external\external_value(PARAM_INT, 'primarycourseunits'),
            'colecturercourseunits' => new \core_external\external_value(PARAM_INT, 'colecturercourseunits'),
            'activemoodlecourses' => new \core_external\external_value(PARAM_INT, 'activemoodlecourses'),
            'totalmoodleteaching' => new \core_external\external_value(PARAM_INT, 'totalmoodleteaching'),
            'legacyunperiodedcount' => new \core_external\external_value(PARAM_INT, 'legacyunperiodedcount'),
            'byacademicyear' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'activecourseunits' => new \core_external\external_value(PARAM_INT, 'activecourseunits'),
                    'activemoodlecourses' => new \core_external\external_value(PARAM_INT, 'activemoodlecourses'),
                ])
            ),
            'bysemester' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'activecourseunits' => new \core_external\external_value(PARAM_INT, 'activecourseunits'),
                    'activemoodlecourses' => new \core_external\external_value(PARAM_INT, 'activemoodlecourses'),
                ])
            ),
            'departments' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(lecturer_department_helper::structure_keys())
            ),
            'courseunits' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(self::course_unit_assignment_structure_keys())
            ),
            'legacycourseunits' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(self::course_unit_assignment_structure_keys())
            ),
            'moodlecourses' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(self::moodle_teaching_structure_keys())
            ),
        ];
    }
}
