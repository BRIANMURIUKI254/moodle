<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

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
                       cu.status AS unitstatus, p.departmentid
                  FROM {local_btiportal_course_unit_teacher} t
                  JOIN {local_btiportal_course_unit} cu ON cu.id = t.courseunitid
             LEFT JOIN {local_btiportal_programme} p ON p.id = cu.programmeid
                 WHERE {$where}
              ORDER BY t.status ASC, cu.code ASC, t.id ASC";

        $rows = $DB->get_records_sql($sql, $params, 0, academic_constants::LIST_LIMIT);
        $out = [];
        foreach ($rows as $row) {
            $yearid = (int)($row->academicyearid ?? 0);
            $semid = (int)($row->semesterid ?? 0);
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
                'moodlecourseid' => (int)$row->moodlecourseid,
                'unitstatus' => (string)$row->unitstatus,
                'islegacy' => 0,
            ];
        }
        return $out;
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

        $roles = $DB->get_records_list('role', 'shortname', academic_constants::MOODLE_TEACHING_ROLES);
        if (!$roles) {
            return [];
        }
        $roleids = array_map(static fn($r) => (int)$r->id, $roles);
        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $params['userid'] = $userid;
        $params['siteid'] = SITEID;
        $params['coursecxt'] = CONTEXT_COURSE;

        $sql = "SELECT ue.id AS enrolmentid, ue.status, ue.timestart, ue.timeend,
                       e.courseid, c.fullname, c.shortname, c.visible, r.shortname AS roleshortname
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.contextlevel = :coursecxt AND ctx.instanceid = c.id
                  JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ue.userid = :userid
                   AND c.id <> :siteid
                   AND r.id {$insql}
              ORDER BY c.fullname ASC";

        $rows = $DB->get_records_sql($sql, $params, 0, academic_constants::LIST_LIMIT);
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $cid = (int)$row->courseid;
            if (isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $out[] = [
                'enrolmentid' => (int)$row->enrolmentid,
                'courseid' => $cid,
                'fullname' => (string)$row->fullname,
                'shortname' => (string)$row->shortname,
                'visible' => (int)$row->visible,
                'role' => (string)$row->roleshortname,
                'status' => (int)$row->status,
                'statuslabel' => ((int)$row->status === 0) ? 'active' : 'inactive',
                'timestart' => (int)$row->timestart,
                'timeend' => (int)$row->timeend,
            ];
        }
        return $out;
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
            'unitstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'unitstatus'),
            'islegacy' => new \core_external\external_value(PARAM_INT, 'islegacy'),
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
