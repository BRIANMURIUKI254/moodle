<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Live academic reports from Moodle + local_btiportal data.
 * Does not invent counts — missing datasets return zero with availability flags.
 */
class department_reports_helper {
    /**
     * @return array
     */
    public static function get_reports(int $userid, ?int $departmentid = null, string $scopesel = 'auto'): array {
        global $DB;

        $scope = department_auth_helper::resolve_scope($userid, $departmentid > 0 ? $departmentid : null);
        if (!$scope['ok']) {
            return [
                'available' => 0,
                'university' => 0,
                'message' => $scope['errors'][0]['message'] ?? 'Not authorised',
                'departmentid' => 0,
                'metrics' => [],
            ];
        }

        $university = $scope['university'] && ($scopesel !== 'department' || !$departmentid);
        $deptids = $scope['departmentids'];
        if ($university && $departmentid > 0) {
            $university = false;
            $deptids = [$departmentid];
        }
        if (!$university && !$deptids) {
            return [
                'available' => 0,
                'university' => 0,
                'message' => 'No authorized departments',
                'departmentid' => 0,
                'metrics' => [],
            ];
        }

        $metrics = [];
        if ($university) {
            $metrics = array_merge($metrics, self::university_metrics());
        }
        $metrics = array_merge($metrics, self::department_metrics($deptids));

        return [
            'available' => 1,
            'university' => $university ? 1 : 0,
            'message' => '',
            'departmentid' => ($deptids && count($deptids) === 1) ? (int)$deptids[0] : 0,
            'departmentids' => implode(',', array_map('intval', $deptids)),
            'metrics' => $metrics,
            'exportconfigured' => 0,
            'exportmessage' => 'Export is not configured for operational reports.',
        ];
    }

    /**
     * @return list<array{key:string,label:string,value:int,scope:string}>
     */
    private static function university_metrics(): array {
        global $DB;
        $students = (int)$DB->count_records_select(
            'local_btiportal_student_profile',
            "status = 'active'"
        );
        $deferred = (int)$DB->count_records_select(
            'local_btiportal_student_profile',
            "academicstatus = 'deferred'"
        );
        $withdrawn = (int)$DB->count_records_select(
            'local_btiportal_student_profile',
            "academicstatus = 'withdrawn'"
        );
        $programmes = (int)$DB->count_records_select(
            'local_btiportal_programme',
            "status = 'active'"
        );
        $units = (int)$DB->count_records_select(
            'local_btiportal_course_unit',
            "status = 'active'"
        );
        $mapped = (int)$DB->count_records_select(
            'local_btiportal_course_unit',
            "status = 'active' AND courseid > 0"
        );
        $timetable = (int)$DB->count_records_select(
            'local_btiportal_timetable',
            "status = 'published'"
        );
        $conflicts = self::count_timetable_conflicts([]);

        return [
            ['key' => 'student_population', 'label' => 'Student population', 'value' => $students, 'scope' => 'university'],
            ['key' => 'deferred_students', 'label' => 'Deferred students', 'value' => $deferred, 'scope' => 'university'],
            ['key' => 'withdrawn_students', 'label' => 'Withdrawn students', 'value' => $withdrawn, 'scope' => 'university'],
            ['key' => 'active_programmes', 'label' => 'Active programmes', 'value' => $programmes, 'scope' => 'university'],
            ['key' => 'active_course_units', 'label' => 'Active Course Units', 'value' => $units, 'scope' => 'university'],
            ['key' => 'mapped_course_units', 'label' => 'Course Units mapped to Moodle', 'value' => $mapped, 'scope' => 'university'],
            ['key' => 'published_timetable', 'label' => 'Published timetable entries', 'value' => $timetable, 'scope' => 'university'],
            ['key' => 'timetable_conflicts', 'label' => 'Detected timetable conflicts', 'value' => $conflicts, 'scope' => 'university'],
        ];
    }

    /**
     * @param list<int> $deptids
     * @return list<array{key:string,label:string,value:int,scope:string}>
     */
    private static function department_metrics(array $deptids): array {
        global $DB;
        if (!$deptids) {
            // University-wide department rollup already covered.
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($deptids, SQL_PARAMS_NAMED, 'd');

        $programmes = (int)$DB->count_records_select(
            'local_btiportal_programme',
            "status = 'active' AND departmentid {$insql}",
            $params
        );

        // Students linked via programme department.
        $studentcount = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT sp.userid)
               FROM {local_btiportal_student_profile} sp
               JOIN {local_btiportal_programme} p ON p.id = sp.programmeid
              WHERE sp.status = 'active' AND p.departmentid {$insql}",
            $params
        );
        $deferred = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT sp.userid)
               FROM {local_btiportal_student_profile} sp
               JOIN {local_btiportal_programme} p ON p.id = sp.programmeid
              WHERE sp.academicstatus = 'deferred' AND p.departmentid {$insql}",
            $params
        );
        $withdrawn = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT sp.userid)
               FROM {local_btiportal_student_profile} sp
               JOIN {local_btiportal_programme} p ON p.id = sp.programmeid
              WHERE sp.academicstatus = 'withdrawn' AND p.departmentid {$insql}",
            $params
        );

        $lecturers = (int)$DB->count_records_select(
            'local_btiportal_lecturer_dept',
            "status = 'active' AND departmentid {$insql}",
            $params
        );

        $timetable = (int)$DB->count_records_select(
            'local_btiportal_timetable',
            "status IN ('draft','published') AND departmentid {$insql}",
            $params
        );
        $conflicts = self::count_timetable_conflicts($deptids);

        // Course units via programmes in department (curriculum path may vary — use programmeid when present).
        $units = 0;
        if ($DB->get_manager()->table_exists('local_btiportal_course_unit')) {
            $units = (int)$DB->count_records_sql(
                "SELECT COUNT(cu.id)
                   FROM {local_btiportal_course_unit} cu
                   JOIN {local_btiportal_curriculum} c ON c.id = cu.curriculumid
                   JOIN {local_btiportal_programme} p ON p.id = c.programmeid
                  WHERE cu.status = 'active' AND p.departmentid {$insql}",
                $params
            );
        }

        $mapped = 0;
        if ($DB->get_manager()->table_exists('local_btiportal_course_unit')) {
            $mapped = (int)$DB->count_records_sql(
                "SELECT COUNT(cu.id)
                   FROM {local_btiportal_course_unit} cu
                   JOIN {local_btiportal_curriculum} c ON c.id = cu.curriculumid
                   JOIN {local_btiportal_programme} p ON p.id = c.programmeid
                  WHERE cu.status = 'active' AND cu.courseid > 0 AND p.departmentid {$insql}",
                $params
            );
        }

        return [
            ['key' => 'dept_student_totals', 'label' => 'Department student totals', 'value' => $studentcount, 'scope' => 'department'],
            ['key' => 'dept_deferred', 'label' => 'Department deferred students', 'value' => $deferred, 'scope' => 'department'],
            ['key' => 'dept_withdrawn', 'label' => 'Department withdrawn students', 'value' => $withdrawn, 'scope' => 'department'],
            ['key' => 'dept_programmes', 'label' => 'Department programmes', 'value' => $programmes, 'scope' => 'department'],
            ['key' => 'dept_course_units', 'label' => 'Department Course Units', 'value' => $units, 'scope' => 'department'],
            ['key' => 'dept_mapped_units', 'label' => 'Department Moodle Course mappings', 'value' => $mapped, 'scope' => 'department'],
            ['key' => 'dept_lecturers', 'label' => 'Department lecturers', 'value' => $lecturers, 'scope' => 'department'],
            ['key' => 'dept_timetable_coverage', 'label' => 'Department timetable entries', 'value' => $timetable, 'scope' => 'department'],
            ['key' => 'dept_timetable_conflicts', 'label' => 'Department timetable conflicts', 'value' => $conflicts, 'scope' => 'department'],
        ];
    }

    /**
     * Approximate conflict count using pairwise overlap among published/draft entries.
     *
     * @param list<int> $deptids
     */
    private static function count_timetable_conflicts(array $deptids): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_btiportal_timetable')) {
            return 0;
        }
        $params = [];
        $where = "status IN ('draft','published')";
        if ($deptids) {
            list($insql, $inparams) = $DB->get_in_or_equal($deptids, SQL_PARAMS_NAMED, 'd');
            $where .= " AND departmentid {$insql}";
            $params = $inparams;
        }
        $rows = $DB->get_records_select('local_btiportal_timetable', $where, $params, 'sessiondate ASC, starttime ASC');
        $list = array_values($rows);
        $count = 0;
        $n = count($list);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $list[$i];
                $b = $list[$j];
                if ((int)$a->sessiondate !== (int)$b->sessiondate) {
                    break;
                }
                if ((int)$a->starttime >= (int)$b->endtime || (int)$b->starttime >= (int)$a->endtime) {
                    continue;
                }
                if ((int)$a->lecturerid > 0 && (int)$a->lecturerid === (int)$b->lecturerid) {
                    $count++;
                    continue;
                }
                if ((int)$a->courseunitid > 0 && (int)$a->courseunitid === (int)$b->courseunitid) {
                    $count++;
                    continue;
                }
                $ra = trim((string)$a->room);
                $rb = trim((string)$b->room);
                if ($ra !== '' && strcasecmp($ra, $rb) === 0) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function structure_keys(): array {
        return [
            'available' => new \core_external\external_value(PARAM_INT, 'available'),
            'university' => new \core_external\external_value(PARAM_INT, 'university scope'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'departmentids' => new \core_external\external_value(PARAM_TEXT, 'csv department ids'),
            'exportconfigured' => new \core_external\external_value(PARAM_INT, 'exportconfigured'),
            'exportmessage' => new \core_external\external_value(PARAM_TEXT, 'exportmessage'),
            'metrics' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'key' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'key'),
                    'label' => new \core_external\external_value(PARAM_TEXT, 'label'),
                    'value' => new \core_external\external_value(PARAM_INT, 'value'),
                    'scope' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'scope'),
                ])
            ),
        ];
    }
}
