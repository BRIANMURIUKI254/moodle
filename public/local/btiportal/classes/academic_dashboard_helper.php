<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only rollup of the academic catalogue for the admin dashboard / Academic Structure hub.
 */
class academic_dashboard_helper {
    /**
     * @return array
     */
    public static function get_admin_academic_dashboard(): array {
        global $DB;

        $counts = [
            'schools' => self::count('local_btiportal_school'),
            'activeschools' => self::count('local_btiportal_school', ['status' => 'active']),
            'departments' => self::count('local_btiportal_department'),
            'activedepartments' => self::count('local_btiportal_department', ['status' => 'active']),
            'programmes' => self::count('local_btiportal_programme'),
            'activeprogrammes' => self::count('local_btiportal_programme', ['status' => 'active']),
            'curricula' => self::count('local_btiportal_curriculum'),
            'activecurricula' => self::count('local_btiportal_curriculum', ['status' => 'active']),
            'courseunits' => self::count('local_btiportal_course_unit'),
            'activecourseunits' => self::count('local_btiportal_course_unit', ['status' => 'active']),
            'unitswithoutteachers' => self::count_units_without_teachers(),
            'lecturerassignments' => self::count('local_btiportal_lecturer_dept', ['status' => 'active']),
            'staffassignments' => self::count('local_btiportal_staff_org', ['status' => 'active']),
            'studentprofiles' => self::count('local_btiportal_student_profile', ['status' => 'active']),
            'activestudents' => self::count('local_btiportal_student_profile', ['academicstatus' => 'active']),
            'deferredstudents' => self::count('local_btiportal_student_profile', ['academicstatus' => 'deferred']),
            'withdrawnstudents' => self::count('local_btiportal_student_profile', ['academicstatus' => 'withdrawn']),
            'readmissionsperiod' => self::count_lifecycle_since('readmission', self::period_start()),
            'programmetransfersperiod' => self::count_lifecycle_since('programme_transfer', self::period_start())
                + self::count_lifecycle_since('programme_change', self::period_start()),
            'activecourseunitassignments' => self::count('local_btiportal_course_unit_teacher', ['status' => 'active']),
            'lecturersmulticourseunits' => self::count_lecturers_with_multiple_units(),
            'mappedcourseunits' => self::count_mapped_course_units(),
            'unmappedcourseunits' => self::count_unmapped_course_units(),
            'requiredcourseunits' => self::count('local_btiportal_course_unit', ['isrequired' => 1, 'status' => 'active']),
            'unitswithoutlecturersperiod' => self::count_units_without_teachers(),
            'semesterprogressionsperiod' => self::count_lifecycle_since('semester_progression', self::period_start()),
            'promotionsperiod' => self::count_lifecycle_since('promotion', self::period_start()),
            'legacyunperiodedassignments' => self::count_legacy_teacher_assignments(),
            'enrolmentopscompleted' => self::count_enrol_ops('completed'),
            'enrolmentopspartial' => self::count_enrol_ops('partial'),
            'enrolmentopsfailed' => self::count_enrol_ops('failed'),
            'incompletedeliveryplans' => self::count_incomplete_delivery_profiles(),
            'studentswithelectivespending' => self::count_students_electives_pending(),
            'academicyears' => self::count('local_btiportal_academic_year'),
            'activeacademicyears' => self::count('local_btiportal_academic_year', ['status' => 'active']),
            'semesters' => self::count('local_btiportal_semester'),
            'activesemesters' => self::count('local_btiportal_semester', ['status' => 'active']),
        ];

        $currentyear = null;
        $currentsemester = null;
        if (self::table_exists('local_btiportal_academic_year')) {
            $row = $DB->get_record('local_btiportal_academic_year', ['iscurrent' => 1], '*', IGNORE_MULTIPLE);
            if ($row) {
                $currentyear = academic_calendar_helper::format_year($row);
            }
        }
        if (self::table_exists('local_btiportal_semester')) {
            $row = $DB->get_record('local_btiportal_semester', ['iscurrent' => 1], '*', IGNORE_MULTIPLE);
            if ($row) {
                $currentsemester = academic_calendar_helper::format_semester($row);
            }
        }

        $alerts = self::build_configuration_alerts($counts, $currentyear, $currentsemester);

        // Optional single structures must be omitted rather than sent as null.
        $out = [
            'counts' => $counts,
            'alerts' => array_values(array_map(static function (array $a): string {
                return (string)$a['message'];
            }, $alerts)),
            'configalerts' => array_values($alerts),
            'generatedat' => time(),
        ];
        if ($currentyear !== null) {
            $out['currentacademicyear'] = $currentyear;
        }
        if ($currentsemester !== null) {
            $out['currentsemester'] = $currentsemester;
        }
        return $out;
    }

    /**
     * @param array $counts
     * @param array|null $currentyear
     * @param array|null $currentsemester
     * @return list<array{code:string,message:string,actionpath:string,entityid:int}>
     */
    private static function build_configuration_alerts(
        array $counts,
        ?array $currentyear,
        ?array $currentsemester
    ): array {
        global $DB;

        $alerts = [];

        if ($counts['schools'] === 0) {
            $alerts[] = self::alert('no_school', 'No School configured.', '/admin/academic/schools');
        }
        if ($counts['activedepartments'] === 0 && $counts['schools'] > 0) {
            $alerts[] = self::alert(
                'no_department',
                'No active Department has been created yet.',
                '/admin/academic/departments'
            );
        }
        if ($counts['activeprogrammes'] === 0 && $counts['activedepartments'] > 0) {
            $alerts[] = self::alert(
                'no_programme',
                'No active Programme has been created yet.',
                '/admin/academic/programmes'
            );
        }
        if ($counts['activecurricula'] === 0 && $counts['activeprogrammes'] > 0) {
            $alerts[] = self::alert(
                'no_curriculum',
                'No active Curriculum has been published yet.',
                '/admin/academic/curricula'
            );
        }
        if ($currentyear === null) {
            $alerts[] = self::alert(
                'no_current_year',
                'No current Academic Year.',
                '/admin/academic/years'
            );
        }
        if ($currentsemester === null) {
            $alerts[] = self::alert(
                'no_current_semester',
                'No current Institutional Semester.',
                '/admin/academic/semesters'
            );
        }

        // Schools without departments.
        if (self::table_exists('local_btiportal_school') && self::table_exists('local_btiportal_department')) {
            $sql = "SELECT s.id, s.name
                      FROM {local_btiportal_school} s
                     WHERE s.status = :sstatus
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_btiportal_department} d
                            WHERE d.schoolid = s.id AND d.status <> :archived
                       )";
            $rows = $DB->get_records_sql($sql, ['sstatus' => 'active', 'archived' => 'archived'], 0, 20);
            foreach ($rows as $row) {
                $alerts[] = self::alert(
                    'school_no_departments',
                    'School has no Departments: ' . $row->name,
                    '/admin/academic/departments?schoolId=' . (int)$row->id,
                    (int)$row->id
                );
            }
        }

        // Departments without programmes.
        if (self::table_exists('local_btiportal_department') && self::table_exists('local_btiportal_programme')) {
            $sql = "SELECT d.id, d.name
                      FROM {local_btiportal_department} d
                     WHERE d.status = :dstatus
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_btiportal_programme} p
                            WHERE p.departmentid = d.id AND p.status <> :archived
                       )";
            $rows = $DB->get_records_sql($sql, ['dstatus' => 'active', 'archived' => 'archived'], 0, 20);
            foreach ($rows as $row) {
                $alerts[] = self::alert(
                    'department_no_programmes',
                    'Department has no Programmes: ' . $row->name,
                    '/admin/academic/programmes?departmentId=' . (int)$row->id,
                    (int)$row->id
                );
            }
        }

        // Programmes without an active curriculum.
        if (self::table_exists('local_btiportal_programme') && self::table_exists('local_btiportal_curriculum')) {
            $sql = "SELECT p.id, p.name
                      FROM {local_btiportal_programme} p
                     WHERE p.status = :pstatus
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_btiportal_curriculum} c
                            WHERE c.programmeid = p.id AND c.status = :cstatus
                       )";
            $rows = $DB->get_records_sql($sql, ['pstatus' => 'active', 'cstatus' => 'active'], 0, 20);
            foreach ($rows as $row) {
                $alerts[] = self::alert(
                    'programme_no_curriculum',
                    'Programme has no active Curriculum: ' . $row->name,
                    '/admin/academic/curricula?programmeId=' . (int)$row->id,
                    (int)$row->id
                );
            }
        }

        // Academic years without institutional semesters.
        if (self::table_exists('local_btiportal_academic_year') && self::table_exists('local_btiportal_semester')) {
            $sql = "SELECT y.id, y.name
                      FROM {local_btiportal_academic_year} y
                     WHERE y.status <> :archived
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_btiportal_semester} s
                            WHERE s.academicyearid = y.id AND s.status <> :sarchived
                       )";
            $rows = $DB->get_records_sql($sql, ['archived' => 'archived', 'sarchived' => 'archived'], 0, 20);
            foreach ($rows as $row) {
                $alerts[] = self::alert(
                    'year_no_semesters',
                    'Academic Year has no Semesters: ' . $row->name,
                    '/admin/academic/semesters?academicYearId=' . (int)$row->id,
                    (int)$row->id
                );
            }
        }

        // Curricula without curriculum years.
        if (self::table_exists('local_btiportal_curriculum') && self::table_exists('local_btiportal_curriculum_year')) {
            $sql = "SELECT c.id, c.name
                      FROM {local_btiportal_curriculum} c
                     WHERE c.status <> :archived
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_btiportal_curriculum_year} cy
                            WHERE cy.curriculumid = c.id AND cy.status <> :cyarchived
                       )";
            $rows = $DB->get_records_sql($sql, ['archived' => 'archived', 'cyarchived' => 'archived'], 0, 20);
            foreach ($rows as $row) {
                $alerts[] = self::alert(
                    'curriculum_no_years',
                    'Curriculum has no Curriculum Years: ' . $row->name,
                    '/admin/academic/curricula',
                    (int)$row->id
                );
            }
        }

        // Curriculum years without curriculum semesters.
        if (self::table_exists('local_btiportal_curriculum_year')
            && self::table_exists('local_btiportal_curriculum_semester')) {
            $sql = "SELECT cy.id, cy.name, cy.curriculumid
                      FROM {local_btiportal_curriculum_year} cy
                     WHERE cy.status <> :archived
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_btiportal_curriculum_semester} cs
                            WHERE cs.curriculumyearid = cy.id AND cs.status <> :csarchived
                       )";
            $rows = $DB->get_records_sql($sql, ['archived' => 'archived', 'csarchived' => 'archived'], 0, 20);
            foreach ($rows as $row) {
                $alerts[] = self::alert(
                    'curriculum_year_no_semesters',
                    'Curriculum Year has no Curriculum Semesters: ' . $row->name,
                    '/admin/academic/curricula',
                    (int)$row->curriculumid
                );
            }
        }

        // Curriculum semesters without course units (by curriculum + year/semester ints).
        if (self::table_exists('local_btiportal_curriculum_semester')
            && self::table_exists('local_btiportal_curriculum_year')
            && self::table_exists('local_btiportal_course_unit')) {
            $sql = "SELECT cs.id, cs.name, cy.curriculumid, cy.yearnumber, cs.semesternumber
                      FROM {local_btiportal_curriculum_semester} cs
                      JOIN {local_btiportal_curriculum_year} cy ON cy.id = cs.curriculumyearid
                     WHERE cs.status <> :archived
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_btiportal_course_unit} cu
                            WHERE cu.curriculumid = cy.curriculumid
                              AND cu.yearofstudy = cy.yearnumber
                              AND cu.semestersequence = cs.semesternumber
                              AND cu.status <> :cuarchived
                       )";
            $rows = $DB->get_records_sql($sql, ['archived' => 'archived', 'cuarchived' => 'archived'], 0, 20);
            foreach ($rows as $row) {
                $alerts[] = self::alert(
                    'curriculum_semester_no_units',
                    'Curriculum Semester has no Course Units: ' . $row->name,
                    '/admin/course-units',
                    (int)$row->curriculumid
                );
            }
        }

        if ($counts['unitswithoutteachers'] > 0) {
            $alerts[] = self::alert(
                'units_without_teachers',
                $counts['unitswithoutteachers'] . ' active course unit(s) have no assigned teacher.',
                '/admin/course-units'
            );
        }

        $orphanprogrammes = self::count_programmes_without_department();
        if ($orphanprogrammes > 0) {
            $alerts[] = self::alert(
                'orphan_programmes',
                $orphanprogrammes . ' programme(s) are not linked to a department.',
                '/admin/academic/programmes'
            );
        }

        if (($counts['incompletedeliveryplans'] ?? 0) > 0) {
            $alerts[] = self::alert(
                'incomplete_delivery_plans',
                $counts['incompletedeliveryplans'] . ' active student(s) have incomplete curriculum placement.',
                '/admin/students?deliveryStatus=incomplete'
            );
        }
        if (($counts['studentswithelectivespending'] ?? 0) > 0) {
            $alerts[] = self::alert(
                'electives_pending',
                $counts['studentswithelectivespending'] . ' active student(s) have elective selections pending.',
                '/admin/students?deliveryStatus=elective_required'
            );
        }
        if (($counts['enrolmentopsfailed'] ?? 0) > 0) {
            $alerts[] = self::alert(
                'enrolment_ops_failed',
                $counts['enrolmentopsfailed'] . ' academic enrolment operation(s) failed.',
                '/admin/students?enrolmentStatus=failed'
            );
        }

        return $alerts;
    }

    /**
     * @return array{code:string,message:string,actionpath:string,entityid:int}
     */
    private static function alert(string $code, string $message, string $actionpath, int $entityid = 0): array {
        return [
            'code' => $code,
            'message' => $message,
            'actionpath' => $actionpath,
            'entityid' => $entityid,
        ];
    }

    private static function table_exists(string $table): bool {
        global $DB;
        return $DB->get_manager()->table_exists($table);
    }

    private static function count(string $table, array $conditions = []): int {
        global $DB;
        if (!self::table_exists($table)) {
            return 0;
        }
        return (int)$DB->count_records($table, $conditions);
    }

    private static function count_units_without_teachers(): int {
        global $DB;
        if (!self::table_exists('local_btiportal_course_unit')) {
            return 0;
        }
        if (!self::table_exists('local_btiportal_course_unit_teacher')) {
            return (int)$DB->count_records('local_btiportal_course_unit', ['status' => 'active']);
        }
        $sql = "SELECT COUNT(cu.id)
                  FROM {local_btiportal_course_unit} cu
                 WHERE cu.status = :unitstatus
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_btiportal_course_unit_teacher} cut
                        WHERE cut.courseunitid = cu.id
                          AND cut.status = :teacherstatus
                   )";
        return (int)$DB->count_records_sql($sql, ['unitstatus' => 'active', 'teacherstatus' => 'active']);
    }

    private static function count_programmes_without_department(): int {
        global $DB;
        if (!self::table_exists('local_btiportal_programme')) {
            return 0;
        }
        return (int)$DB->count_records_select(
            'local_btiportal_programme',
            'departmentid = 0 AND status = :status',
            ['status' => 'active']
        );
    }

    private static function period_start(): int {
        // Rolling 30-day window for dashboard "during selected period" metrics.
        return time() - (30 * DAYSECS);
    }

    private static function count_lifecycle_since(string $actiontype, int $since): int {
        if (!self::table_exists(student_academic_history_helper::TABLE)) {
            return 0;
        }
        return student_academic_history_helper::count_since($actiontype, $since);
    }

    private static function count_lecturers_with_multiple_units(): int {
        global $DB;
        if (!self::table_exists('local_btiportal_course_unit_teacher')) {
            return 0;
        }
        $sql = "SELECT COUNT(1) FROM (
                    SELECT userid
                      FROM {local_btiportal_course_unit_teacher}
                     WHERE status = :status
                  GROUP BY userid
                    HAVING COUNT(DISTINCT courseunitid) > 1
                ) x";
        return (int)$DB->count_records_sql($sql, ['status' => 'active']);
    }

    private static function count_mapped_course_units(): int {
        global $DB;
        if (!self::table_exists('local_btiportal_course_unit')) {
            return 0;
        }
        return (int)$DB->count_records_select(
            'local_btiportal_course_unit',
            "status = 'active' AND courseid > 1"
        );
    }

    private static function count_unmapped_course_units(): int {
        global $DB;
        if (!self::table_exists('local_btiportal_course_unit')) {
            return 0;
        }
        return (int)$DB->count_records_select(
            'local_btiportal_course_unit',
            "status = 'active' AND (courseid IS NULL OR courseid <= 1)"
        );
    }

    private static function count_legacy_teacher_assignments(): int {
        global $DB;
        if (!self::table_exists('local_btiportal_course_unit_teacher')) {
            return 0;
        }
        // Column may not exist before Phase 26 upgrade.
        try {
            return (int)$DB->count_records_select(
                'local_btiportal_course_unit_teacher',
                "status = 'active' AND academicyearid = 0 AND semesterid = 0"
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function count_enrol_ops(string $status): int {
        global $DB;
        $table = 'local_btiportal_enrol_ops';
        if (!self::table_exists($table)) {
            return 0;
        }
        return (int)$DB->count_records($table, ['status' => $status]);
    }

    private static function count_incomplete_delivery_profiles(): int {
        global $DB;
        if (!self::table_exists('local_btiportal_student_profile')) {
            return 0;
        }
        return (int)$DB->count_records_select(
            'local_btiportal_student_profile',
            "academicstatus = :astatus AND status = :pstatus
               AND (curriculumyearid = 0 OR curriculumsemesterid = 0)",
            ['astatus' => 'active', 'pstatus' => 'active']
        );
    }

    /**
     * Approximate: active students with at least one active elective group for their
     * year/sem numbers and zero confirmed selections in the current institutional period.
     */
    private static function count_students_electives_pending(): int {
        global $DB;
        if (!elective_helper::tables_ready() || !self::table_exists('local_btiportal_student_profile')) {
            return 0;
        }
        if (!self::table_exists('local_btiportal_curriculum_year')
            || !self::table_exists('local_btiportal_curriculum_semester')) {
            return 0;
        }

        $ay = 0;
        $sem = 0;
        if (self::table_exists('local_btiportal_academic_year')) {
            $row = $DB->get_record('local_btiportal_academic_year', ['iscurrent' => 1], '*', IGNORE_MULTIPLE);
            $ay = $row ? (int)$row->id : 0;
        }
        if (self::table_exists('local_btiportal_semester')) {
            $row = $DB->get_record('local_btiportal_semester', ['iscurrent' => 1], '*', IGNORE_MULTIPLE);
            $sem = $row ? (int)$row->id : 0;
        }
        if ($ay <= 0 || $sem <= 0) {
            return 0;
        }

        $sql = "SELECT COUNT(DISTINCT sp.userid)
                  FROM {local_btiportal_student_profile} sp
                  JOIN {local_btiportal_curriculum_year} cy ON cy.id = sp.curriculumyearid
                  JOIN {local_btiportal_curriculum_semester} cs ON cs.id = sp.curriculumsemesterid
                  JOIN {" . elective_helper::GROUP_TABLE . "} eg
                    ON eg.curriculumid = sp.curriculumid
                   AND eg.yearofstudy = cy.yearnumber
                   AND eg.semestersequence = cs.semesternumber
                   AND eg.status = :egstatus
                 WHERE sp.academicstatus = :astatus
                   AND sp.status = :pstatus
                   AND NOT EXISTS (
                       SELECT 1 FROM {" . elective_helper::SELECTION_TABLE . "} sel
                        WHERE sel.userid = sp.userid
                          AND sel.electivegroupid = eg.id
                          AND sel.academicyearid = :ay
                          AND sel.semesterid = :sem
                          AND sel.status = :selstatus
                   )";
        return (int)$DB->count_records_sql($sql, [
            'egstatus' => 'active',
            'astatus' => 'active',
            'pstatus' => 'active',
            'ay' => $ay,
            'sem' => $sem,
            'selstatus' => 'confirmed',
        ]);
    }

    public static function structure_keys(): array {
        return [
            'counts' => new \core_external\external_single_structure([
                'schools' => new \core_external\external_value(PARAM_INT, 'schools'),
                'activeschools' => new \core_external\external_value(PARAM_INT, 'activeschools'),
                'departments' => new \core_external\external_value(PARAM_INT, 'departments'),
                'activedepartments' => new \core_external\external_value(PARAM_INT, 'activedepartments'),
                'programmes' => new \core_external\external_value(PARAM_INT, 'programmes'),
                'activeprogrammes' => new \core_external\external_value(PARAM_INT, 'activeprogrammes'),
                'curricula' => new \core_external\external_value(PARAM_INT, 'curricula'),
                'activecurricula' => new \core_external\external_value(PARAM_INT, 'activecurricula'),
                'courseunits' => new \core_external\external_value(PARAM_INT, 'courseunits'),
                'activecourseunits' => new \core_external\external_value(PARAM_INT, 'activecourseunits'),
                'unitswithoutteachers' => new \core_external\external_value(PARAM_INT, 'unitswithoutteachers'),
                'lecturerassignments' => new \core_external\external_value(PARAM_INT, 'lecturerassignments'),
                'staffassignments' => new \core_external\external_value(PARAM_INT, 'staffassignments'),
                'studentprofiles' => new \core_external\external_value(PARAM_INT, 'studentprofiles'),
                'activestudents' => new \core_external\external_value(PARAM_INT, 'activestudents'),
                'deferredstudents' => new \core_external\external_value(PARAM_INT, 'deferredstudents'),
                'withdrawnstudents' => new \core_external\external_value(PARAM_INT, 'withdrawnstudents'),
                'readmissionsperiod' => new \core_external\external_value(PARAM_INT, 'readmissionsperiod'),
                'programmetransfersperiod' => new \core_external\external_value(PARAM_INT, 'programmetransfersperiod'),
                'activecourseunitassignments' => new \core_external\external_value(PARAM_INT, 'activecourseunitassignments'),
                'lecturersmulticourseunits' => new \core_external\external_value(PARAM_INT, 'lecturersmulticourseunits'),
                'mappedcourseunits' => new \core_external\external_value(PARAM_INT, 'mappedcourseunits'),
                'unmappedcourseunits' => new \core_external\external_value(PARAM_INT, 'unmappedcourseunits'),
                'requiredcourseunits' => new \core_external\external_value(PARAM_INT, 'requiredcourseunits'),
                'unitswithoutlecturersperiod' => new \core_external\external_value(PARAM_INT, 'unitswithoutlecturersperiod'),
                'semesterprogressionsperiod' => new \core_external\external_value(PARAM_INT, 'semesterprogressionsperiod'),
                'promotionsperiod' => new \core_external\external_value(PARAM_INT, 'promotionsperiod'),
                'legacyunperiodedassignments' => new \core_external\external_value(PARAM_INT, 'legacyunperiodedassignments'),
                'enrolmentopscompleted' => new \core_external\external_value(PARAM_INT, 'enrolmentopscompleted'),
                'enrolmentopspartial' => new \core_external\external_value(PARAM_INT, 'enrolmentopspartial'),
                'enrolmentopsfailed' => new \core_external\external_value(PARAM_INT, 'enrolmentopsfailed'),
                'incompletedeliveryplans' => new \core_external\external_value(PARAM_INT, 'incompletedeliveryplans'),
                'studentswithelectivespending' => new \core_external\external_value(PARAM_INT, 'studentswithelectivespending'),
                'academicyears' => new \core_external\external_value(PARAM_INT, 'academicyears'),
                'activeacademicyears' => new \core_external\external_value(PARAM_INT, 'activeacademicyears'),
                'semesters' => new \core_external\external_value(PARAM_INT, 'semesters'),
                'activesemesters' => new \core_external\external_value(PARAM_INT, 'activesemesters'),
            ]),
            'currentacademicyear' => new \core_external\external_single_structure(
                academic_calendar_helper::year_structure_keys(),
                'Current academic year',
                VALUE_OPTIONAL
            ),
            'currentsemester' => new \core_external\external_single_structure(
                academic_calendar_helper::semester_structure_keys(),
                'Current semester',
                VALUE_OPTIONAL
            ),
            'alerts' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_TEXT, 'Alert message')
            ),
            'configalerts' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'code' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'Alert code'),
                    'message' => new \core_external\external_value(PARAM_TEXT, 'Alert message'),
                    'actionpath' => new \core_external\external_value(PARAM_TEXT, 'Portal action path'),
                    'entityid' => new \core_external\external_value(PARAM_INT, 'Related entity id'),
                ])
            ),
            'generatedat' => new \core_external\external_value(PARAM_INT, 'generatedat'),
        ];
    }
}
