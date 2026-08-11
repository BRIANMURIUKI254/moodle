<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves Curriculum → Year → Semester → Course Units and Moodle delivery mappings.
 *
 * Reuses local_btiportal_course_unit (yearofstudy / semestersequence ints matched to
 * curriculum_year.yearnumber / curriculum_semester.semesternumber). Moodle course
 * identity remains course_unit.courseid.
 */
class curriculum_delivery_helper {
    /**
     * Full curriculum delivery tree with course units and Moodle mapping status.
     */
    public static function get_curriculum_delivery(int $curriculumid): array {
        global $DB;

        $curriculum = $DB->get_record('local_btiportal_curriculum', ['id' => $curriculumid]);
        if (!$curriculum) {
            return [
                'ok' => false,
                'errors' => [['field' => 'curriculumid', 'message' => 'Curriculum not found']],
            ];
        }

        $years = curriculum_structure_helper::list_years($curriculumid, null);
        $yearnodes = [];
        $totals = [
            'courseunits' => 0,
            'required' => 0,
            'elective' => 0,
            'mapped' => 0,
            'unmapped' => 0,
            'missingmoodle' => 0,
            'withteachers' => 0,
            'withoutteachers' => 0,
        ];

        foreach ($years as $year) {
            $semesters = curriculum_structure_helper::list_semesters((int)$year['id'], null);
            $semnodes = [];
            foreach ($semesters as $sem) {
                $units = self::list_course_units_for_semester(
                    $curriculumid,
                    (int)$year['yearnumber'],
                    (int)$sem['semesternumber']
                );
                $semstats = self::summarise_units($units);
                foreach ($semstats as $k => $v) {
                    if (isset($totals[$k])) {
                        $totals[$k] += $v;
                    }
                }
                $semnodes[] = array_merge($sem, [
                    'courseunits' => $units,
                    'counts' => $semstats,
                ]);
            }
            $yearnodes[] = array_merge($year, ['semesters' => $semnodes]);
        }

        $alerts = self::build_alerts($curriculumid, $yearnodes, $totals);

        return [
            'ok' => true,
            'record' => [
                'curriculumid' => $curriculumid,
                'programmeid' => (int)$curriculum->programmeid,
                'code' => (string)$curriculum->code,
                'name' => (string)$curriculum->name,
                'status' => (string)$curriculum->status,
                'years' => $yearnodes,
                'totals' => $totals,
                'alerts' => $alerts,
            ],
        ];
    }

    /**
     * Course units for a curriculum year/semester placement (by numbers).
     *
     * @return list<array>
     */
    public static function list_course_units_for_semester(
        int $curriculumid,
        int $yearnumber,
        int $semesternumber,
        ?string $status = null
    ): array {
        global $DB;

        $params = [
            'curriculumid' => $curriculumid,
            'yearofstudy' => $yearnumber,
            'semestersequence' => $semesternumber,
        ];
        $where = 'cu.curriculumid = :curriculumid
                  AND cu.yearofstudy = :yearofstudy
                  AND cu.semestersequence = :semestersequence';
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND cu.status = :status';
            $params['status'] = strtolower($status);
        } else {
            $where .= " AND cu.status <> 'archived'";
        }

        $sql = "SELECT cu.*
                  FROM {local_btiportal_course_unit} cu
                 WHERE {$where}
              ORDER BY cu.code ASC, cu.id ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, academic_constants::LIST_LIMIT);

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::enrich_unit(academic_course_unit_helper::format($row));
        }
        return $out;
    }

    /**
     * Course units for student placement FKs (curriculumyearid / curriculumsemesterid).
     *
     * @return list<array>
     */
    public static function list_course_units_for_placement(
        int $curriculumid,
        int $curriculumyearid,
        int $curriculumsemesterid
    ): array {
        global $DB;

        $yearnumber = 0;
        $semesternumber = 0;
        if ($curriculumyearid > 0) {
            $year = $DB->get_record('local_btiportal_curriculum_year', ['id' => $curriculumyearid]);
            if ($year) {
                $yearnumber = (int)$year->yearnumber;
            }
        }
        if ($curriculumsemesterid > 0) {
            $sem = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $curriculumsemesterid]);
            if ($sem) {
                $semesternumber = (int)$sem->semesternumber;
                if ($yearnumber <= 0) {
                    $year = $DB->get_record('local_btiportal_curriculum_year', ['id' => (int)$sem->curriculumyearid]);
                    if ($year) {
                        $yearnumber = (int)$year->yearnumber;
                    }
                }
            }
        }
        if ($curriculumid <= 0 || $yearnumber <= 0 || $semesternumber <= 0) {
            return [];
        }
        return self::list_course_units_for_semester($curriculumid, $yearnumber, $semesternumber, 'active');
    }

    /**
     * Map / replace Moodle course on an academic course unit (reuses course_unit.courseid).
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function map_moodle_course(int $courseunitid, int $moodlecourseid, int $sectionid = 0, int $userid = 0): array {
        global $DB;

        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit) {
            return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit not found']]];
        }
        if ($moodlecourseid <= 1 || !$DB->record_exists('course', ['id' => $moodlecourseid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Moodle course not found']]];
        }
        if ($sectionid > 0) {
            $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $moodlecourseid]);
            if (!$section) {
                return ['ok' => false, 'errors' => [['field' => 'sectionid', 'message' => 'Moodle section does not belong to this course']]];
            }
        } else {
            // Keep existing section when only replacing the course if still valid; else clear.
            $sectionid = (int)$unit->sectionid;
            if ($sectionid > 0) {
                $ok = $DB->record_exists('course_sections', ['id' => $sectionid, 'course' => $moodlecourseid]);
                if (!$ok) {
                    $sectionid = 0;
                }
            }
        }

        return academic_course_unit_helper::update($courseunitid, [
            'courseid' => $moodlecourseid,
            'sectionid' => $sectionid,
        ], $userid);
    }

    /**
     * Clear Moodle course mapping (sets courseid to 0) while keeping the academic unit.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function unmap_moodle_course(int $courseunitid, int $userid = 0): array {
        global $DB;
        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit) {
            return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit not found']]];
        }
        // academic_course_unit_helper validates courseid > 1 — update row directly for unmap.
        $unit->courseid = 0;
        $unit->sectionid = 0;
        $unit->timemodified = time();
        $unit->modifiedby = $userid;
        $DB->update_record('local_btiportal_course_unit', $unit);
        return ['ok' => true, 'record' => self::enrich_unit(academic_course_unit_helper::format($unit))];
    }

    /**
     * @param array $unit formatted academic course unit
     */
    public static function enrich_unit(array $unit): array {
        global $DB;

        $courseid = (int)($unit['courseid'] ?? 0);
        $mappingstatus = 'unmapped';
        $moodlename = '';
        $moodleshortname = '';
        $moodlevisible = 0;
        if ($courseid > 1) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,visible');
            if ($course) {
                $mappingstatus = 'mapped';
                $moodlename = (string)$course->fullname;
                $moodleshortname = (string)$course->shortname;
                $moodlevisible = (int)$course->visible;
            } else {
                $mappingstatus = 'missing_moodle_course';
            }
        }

        $teachercount = 0;
        if ($DB->get_manager()->table_exists('local_btiportal_course_unit_teacher')) {
            $teachercount = (int)$DB->count_records('local_btiportal_course_unit_teacher', [
                'courseunitid' => (int)$unit['id'],
                'status' => 'active',
            ]);
        }

        $requirement = !empty($unit['isrequired']) ? 'required' : (string)($unit['unittype'] ?? 'optional');
        if (($unit['unittype'] ?? '') === 'elective') {
            $requirement = 'elective';
        } else if (($unit['unittype'] ?? '') === 'optional') {
            $requirement = 'optional';
        } else if (!empty($unit['isrequired'])) {
            $requirement = 'required';
        }

        return array_merge($unit, [
            'requirementtype' => $requirement,
            'mappingstatus' => $mappingstatus,
            'moodlecourseid' => $courseid,
            'moodlecoursename' => $moodlename,
            'moodlecourseshortname' => $moodleshortname,
            'moodlevisible' => $moodlevisible,
            'activelecturercount' => $teachercount,
        ]);
    }

    /**
     * @param list<array> $units
     * @return array{courseunits:int,required:int,elective:int,mapped:int,unmapped:int,missingmoodle:int,withteachers:int,withoutteachers:int}
     */
    public static function summarise_units(array $units): array {
        $s = [
            'courseunits' => count($units),
            'required' => 0,
            'elective' => 0,
            'mapped' => 0,
            'unmapped' => 0,
            'missingmoodle' => 0,
            'withteachers' => 0,
            'withoutteachers' => 0,
        ];
        foreach ($units as $u) {
            $req = $u['requirementtype'] ?? 'required';
            if ($req === 'required') {
                $s['required']++;
            } else {
                $s['elective']++;
            }
            $map = $u['mappingstatus'] ?? 'unmapped';
            if ($map === 'mapped') {
                $s['mapped']++;
            } else if ($map === 'missing_moodle_course') {
                $s['missingmoodle']++;
            } else {
                $s['unmapped']++;
            }
            if ((int)($u['activelecturercount'] ?? 0) > 0) {
                $s['withteachers']++;
            } else {
                $s['withoutteachers']++;
            }
        }
        return $s;
    }

    /**
     * @param list<array> $yearnodes
     * @return list<array{code:string,message:string,severity:string,actionpath:string,entityid:int}>
     */
    private static function build_alerts(int $curriculumid, array $yearnodes, array $totals): array {
        $alerts = [];
        if ($totals['courseunits'] === 0) {
            $alerts[] = [
                'code' => 'curriculum_no_units',
                'severity' => 'warning',
                'message' => 'Curriculum has no Course Units in any Curriculum Semester.',
                'actionpath' => '/admin/course-units?curriculumId=' . $curriculumid,
                'entityid' => $curriculumid,
            ];
        }
        if ($totals['unmapped'] > 0) {
            $alerts[] = [
                'code' => 'units_unmapped',
                'severity' => 'warning',
                'message' => $totals['unmapped'] . ' required Course Units do not have Moodle Course mappings.',
                'actionpath' => '/admin/course-units?curriculumId=' . $curriculumid . '&mapping=unmapped',
                'entityid' => $curriculumid,
            ];
        }
        if ($totals['missingmoodle'] > 0) {
            $alerts[] = [
                'code' => 'units_missing_moodle',
                'severity' => 'error',
                'message' => $totals['missingmoodle'] . ' Course Unit mapping(s) point to a missing Moodle Course.',
                'actionpath' => '/admin/course-units?curriculumId=' . $curriculumid,
                'entityid' => $curriculumid,
            ];
        }
        if ($totals['withoutteachers'] > 0) {
            $alerts[] = [
                'code' => 'units_without_teachers',
                'severity' => 'warning',
                'message' => $totals['withoutteachers'] . ' Course Units do not have active Lecturer assignments.',
                'actionpath' => '/admin/course-units?curriculumId=' . $curriculumid,
                'entityid' => $curriculumid,
            ];
        }
        foreach ($yearnodes as $year) {
            foreach ($year['semesters'] ?? [] as $sem) {
                if ((int)($sem['counts']['courseunits'] ?? 0) === 0) {
                    $alerts[] = [
                        'code' => 'semester_no_units',
                        'severity' => 'info',
                        'message' => 'Curriculum Semester has no Course Units: ' . ($sem['name'] ?? ''),
                        'actionpath' => '/admin/course-units?curriculumId=' . $curriculumid,
                        'entityid' => (int)$sem['id'],
                    ];
                }
            }
        }
        return $alerts;
    }

    public static function enriched_unit_structure_keys(): array {
        $base = academic_course_unit_helper::structure_keys();
        return array_merge($base, [
            'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
            'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'moodlecoursename' => new \core_external\external_value(PARAM_TEXT, 'moodlecoursename'),
            'moodlecourseshortname' => new \core_external\external_value(PARAM_TEXT, 'moodlecourseshortname'),
            'moodlevisible' => new \core_external\external_value(PARAM_INT, 'moodlevisible'),
            'activelecturercount' => new \core_external\external_value(PARAM_INT, 'activelecturercount'),
        ]);
    }

    public static function delivery_structure_keys(): array {
        return [
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'years' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'id' => new \core_external\external_value(PARAM_INT, 'id'),
                    'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
                    'yearnumber' => new \core_external\external_value(PARAM_INT, 'yearnumber'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'description' => new \core_external\external_value(PARAM_RAW, 'description'),
                    'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
                    'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
                    'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
                    'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
                    'semesters' => new \core_external\external_multiple_structure(
                        new \core_external\external_single_structure([
                            'id' => new \core_external\external_value(PARAM_INT, 'id'),
                            'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
                            'semesternumber' => new \core_external\external_value(PARAM_INT, 'semesternumber'),
                            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                            'description' => new \core_external\external_value(PARAM_RAW, 'description'),
                            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
                            'yearnumber' => new \core_external\external_value(PARAM_INT, 'yearnumber'),
                            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
                            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
                            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
                            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
                            'courseunits' => new \core_external\external_multiple_structure(
                                new \core_external\external_single_structure(self::enriched_unit_structure_keys())
                            ),
                            'counts' => new \core_external\external_single_structure(self::counts_structure_keys()),
                        ])
                    ),
                ])
            ),
            'totals' => new \core_external\external_single_structure(self::counts_structure_keys()),
            'alerts' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'code' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'code'),
                    'severity' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'severity'),
                    'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
                    'actionpath' => new \core_external\external_value(PARAM_TEXT, 'actionpath'),
                    'entityid' => new \core_external\external_value(PARAM_INT, 'entityid'),
                ])
            ),
        ];
    }

    public static function counts_structure_keys(): array {
        return [
            'courseunits' => new \core_external\external_value(PARAM_INT, 'courseunits'),
            'required' => new \core_external\external_value(PARAM_INT, 'required'),
            'elective' => new \core_external\external_value(PARAM_INT, 'elective'),
            'mapped' => new \core_external\external_value(PARAM_INT, 'mapped'),
            'unmapped' => new \core_external\external_value(PARAM_INT, 'unmapped'),
            'missingmoodle' => new \core_external\external_value(PARAM_INT, 'missingmoodle'),
            'withteachers' => new \core_external\external_value(PARAM_INT, 'withteachers'),
            'withoutteachers' => new \core_external\external_value(PARAM_INT, 'withoutteachers'),
        ];
    }
}
