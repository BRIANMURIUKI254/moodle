<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a live Student Academic Delivery Plan from placement + curriculum units + Moodle enrolments.
 */
class student_delivery_plan_helper {
    /**
     * @return array{ok:bool,plan?:array,errors?:list}
     */
    public static function get_plan(int $userid): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Student academic profile not found']]];
        }

        $profile = self::resolve_placement_gaps($profile, true);

        $blockers = self::enrolment_blockers($profile);
        $units = curriculum_delivery_helper::list_course_units_for_placement(
            (int)$profile['curriculumid'],
            (int)$profile['curriculumyearid'],
            (int)$profile['curriculumsemesterid']
        );

        $yearnumber = 0;
        $semesternumber = 0;
        global $DB;
        if ((int)$profile['curriculumyearid'] > 0) {
            $y = $DB->get_record('local_btiportal_curriculum_year', ['id' => (int)$profile['curriculumyearid']]);
            $yearnumber = $y ? (int)$y->yearnumber : 0;
        }
        if ((int)$profile['curriculumsemesterid'] > 0) {
            $s = $DB->get_record('local_btiportal_curriculum_semester', ['id' => (int)$profile['curriculumsemesterid']]);
            $semesternumber = $s ? (int)$s->semesternumber : 0;
        }

        $selected = elective_helper::selected_courseunit_set(
            $userid,
            (int)$profile['academicyearid'],
            (int)$profile['semesterid']
        );
        $electivegroups = elective_helper::list_groups(
            (int)$profile['curriculumid'],
            $yearnumber,
            $semesternumber,
            'active'
        );

        $enrolments = enrolment_transition_helper::list_user_enrolments($userid);
        $enrolbycourse = [];
        foreach ($enrolments as $e) {
            $cid = (int)$e['courseid'];
            if (!isset($enrolbycourse[$cid]) || (int)$e['status'] === 0) {
                $enrolbycourse[$cid] = $e;
            }
        }

        $items = [];
        $summary = [
            'expected' => 0,
            'enrolled' => 0,
            'not_enrolled' => 0,
            'unmapped' => 0,
            'missing_moodle_course' => 0,
            'manual_review' => 0,
            'completed' => 0,
            'ended' => 0,
            'elective_required' => 0,
            'elective_selected' => 0,
        ];

        foreach ($units as $unit) {
            $summary['expected']++;
            $courseid = (int)($unit['moodlecourseid'] ?? $unit['courseid'] ?? 0);
            $map = $unit['mappingstatus'] ?? 'unmapped';
            $req = $unit['requirementtype'] ?? 'required';
            $cuid = (int)$unit['id'];
            $isselectedelective = ($req === 'elective' && isset($selected[$cuid]));

            $state = 'not_enrolled';
            $action = 'enrol';
            $enrolment = null;

            if ($map === 'unmapped') {
                $state = 'unmapped';
                $action = 'map_moodle_course';
                $summary['unmapped']++;
            } else if ($map === 'missing_moodle_course') {
                $state = 'missing_moodle_course';
                $action = 'fix_mapping';
                $summary['missing_moodle_course']++;
            } else if ($req === 'elective' && !$isselectedelective) {
                // Electives require explicit selection before auto-enrol.
                if (isset($enrolbycourse[$courseid]) && (int)$enrolbycourse[$courseid]['status'] === 0) {
                    $enrolment = $enrolbycourse[$courseid];
                    $state = 'enrolled';
                    $action = 'none';
                    $summary['enrolled']++;
                } else {
                    $state = 'manual_review';
                    $action = 'select_elective';
                    $summary['manual_review']++;
                    $summary['elective_required']++;
                }
            } else if (isset($enrolbycourse[$courseid])) {
                $enrolment = $enrolbycourse[$courseid];
                if ((int)$enrolment['status'] === 0) {
                    $state = 'enrolled';
                    $action = 'none';
                    $summary['enrolled']++;
                } else {
                    $state = 'ended';
                    $action = 'reenroll';
                    $summary['ended']++;
                }
                if ($isselectedelective) {
                    $summary['elective_selected']++;
                }
            } else {
                $state = 'not_enrolled';
                $action = 'enrol';
                $summary['not_enrolled']++;
                if ($isselectedelective) {
                    $summary['elective_selected']++;
                }
            }

            $items[] = [
                'courseunitid' => $cuid,
                'code' => (string)$unit['code'],
                'name' => (string)$unit['name'],
                'requirementtype' => $req,
                'yearofstudy' => (int)$unit['yearofstudy'],
                'semestersequence' => (int)$unit['semestersequence'],
                'credithours' => (int)($unit['credithours'] ?? 0),
                'moodlecourseid' => $courseid,
                'moodlecoursename' => (string)($unit['moodlecoursename'] ?? ''),
                'moodlecourseshortname' => (string)($unit['moodlecourseshortname'] ?? ''),
                'mappingstatus' => $map,
                'enrolmentstatus' => $state,
                'enrolmentaction' => $action,
                'enrolmentid' => $enrolment ? (int)$enrolment['enrolmentid'] : 0,
                'completion' => $enrolment['completion'] ?? 'unknown',
                'electiveselected' => $isselectedelective ? 1 : 0,
            ];
        }

        $electiveincomplete = 0;
        foreach ($electivegroups as $g) {
            $sels = [];
            foreach ($selected as $cuid => $_) {
                foreach ($g['units'] as $u) {
                    if ((int)$u['courseunitid'] === (int)$cuid) {
                        $sels[] = (int)$cuid;
                    }
                }
            }
            $v = elective_helper::validate_group_selection($g, $sels);
            if (!$v['ok']) {
                $electiveincomplete++;
            }
        }

        return [
            'ok' => true,
            'plan' => [
                'userid' => $userid,
                'profile' => $profile,
                'blockers' => $blockers,
                'canenrol' => count($blockers) === 0,
                'items' => $items,
                'summary' => $summary,
                'electivegroups' => $electivegroups,
                'electiveincompletegroups' => $electiveincomplete,
                'warning' => 'Academic placement changes do not automatically remove historical Moodle learning records.',
            ],
        ];
    }

    /**
     * Preview delivery units for a placement before a profile exists (admission wizard).
     *
     * @return array{ok:bool,plan?:array,errors?:list}
     */
    public static function preview_for_placement(array $placement, int $userid = 0): array {
        global $DB;

        $curriculumid = (int)($placement['curriculumid'] ?? 0);
        $curriculumyearid = (int)($placement['curriculumyearid'] ?? 0);
        $curriculumsemesterid = (int)($placement['curriculumsemesterid'] ?? 0);
        if ($curriculumid <= 0 || $curriculumyearid <= 0 || $curriculumsemesterid <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'placement', 'message' => 'Curriculum placement is incomplete']]];
        }

        $units = curriculum_delivery_helper::list_course_units_for_placement(
            $curriculumid,
            $curriculumyearid,
            $curriculumsemesterid
        );
        $year = $DB->get_record('local_btiportal_curriculum_year', ['id' => $curriculumyearid]);
        $sem = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $curriculumsemesterid]);
        $groups = elective_helper::list_groups(
            $curriculumid,
            $year ? (int)$year->yearnumber : 0,
            $sem ? (int)$sem->semesternumber : 0,
            'active'
        );

        $enrolbycourse = [];
        if ($userid > 0) {
            foreach (enrolment_transition_helper::list_user_enrolments($userid) as $e) {
                $cid = (int)$e['courseid'];
                if (!isset($enrolbycourse[$cid]) || (int)$e['status'] === 0) {
                    $enrolbycourse[$cid] = $e;
                }
            }
        }

        $items = [];
        $summary = [
            'expected' => 0,
            'enrolled' => 0,
            'not_enrolled' => 0,
            'unmapped' => 0,
            'missing_moodle_course' => 0,
            'manual_review' => 0,
            'completed' => 0,
            'ended' => 0,
            'elective_required' => 0,
            'elective_selected' => 0,
        ];
        foreach ($units as $unit) {
            $summary['expected']++;
            $courseid = (int)($unit['moodlecourseid'] ?? 0);
            $map = (string)($unit['mappingstatus'] ?? 'unmapped');
            $req = (string)($unit['requirementtype'] ?? 'required');
            $state = 'not_enrolled';
            $action = 'enrol';
            if ($map === 'unmapped') {
                $state = 'unmapped';
                $action = 'map_moodle_course';
                $summary['unmapped']++;
            } else if ($map === 'missing_moodle_course') {
                $state = 'missing_moodle_course';
                $summary['missing_moodle_course']++;
            } else if ($req === 'elective') {
                $state = 'manual_review';
                $action = 'select_elective';
                $summary['manual_review']++;
                $summary['elective_required']++;
            } else if (isset($enrolbycourse[$courseid]) && (int)$enrolbycourse[$courseid]['status'] === 0) {
                $state = 'enrolled';
                $action = 'none';
                $summary['enrolled']++;
            } else {
                $summary['not_enrolled']++;
            }
            $items[] = [
                'courseunitid' => (int)$unit['id'],
                'code' => (string)$unit['code'],
                'name' => (string)$unit['name'],
                'requirementtype' => $req,
                'yearofstudy' => (int)$unit['yearofstudy'],
                'semestersequence' => (int)$unit['semestersequence'],
                'credithours' => (int)($unit['credithours'] ?? 0),
                'moodlecourseid' => $courseid,
                'moodlecoursename' => (string)($unit['moodlecoursename'] ?? ''),
                'moodlecourseshortname' => (string)($unit['moodlecourseshortname'] ?? ''),
                'mappingstatus' => $map,
                'enrolmentstatus' => $state,
                'enrolmentaction' => $action,
                'enrolmentid' => 0,
                'completion' => 'unknown',
                'electiveselected' => 0,
            ];
        }

        $profile = [
            'id' => 0,
            'userid' => $userid,
            'schoolid' => (int)($placement['schoolid'] ?? 0),
            'departmentid' => (int)($placement['departmentid'] ?? 0),
            'programmeid' => (int)($placement['programmeid'] ?? 0),
            'curriculumid' => $curriculumid,
            'curriculumyearid' => $curriculumyearid,
            'curriculumsemesterid' => $curriculumsemesterid,
            'academicyearid' => (int)($placement['academicyearid'] ?? 0),
            'semesterid' => (int)($placement['semesterid'] ?? 0),
            'admissiondate' => 0,
            'admissionstatus' => 'pending',
            'academicstatus' => 'active',
            'expectedcompletion' => 0,
            'status' => 'active',
            'entrytype' => '',
            'studymode' => '',
            'deliverymode' => '',
            'campus' => '',
            'intake' => '',
            'scholarship' => '',
            'sponsor' => '',
            'timecreated' => 0,
            'timemodified' => 0,
            'usermodified' => 0,
        ];
        if ($userid > 0) {
            $existing = student_profile_helper::get_by_userid($userid);
            if ($existing) {
                // Prefer explicit placement fields when provided; keep identity/status from profile.
                foreach ([
                    'schoolid', 'departmentid', 'programmeid', 'curriculumid',
                    'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid',
                ] as $key) {
                    if ((int)$profile[$key] <= 0 && isset($existing[$key])) {
                        $profile[$key] = (int)$existing[$key];
                    }
                }
                $profile['id'] = (int)$existing['id'];
                $profile['userid'] = $userid;
                $profile['admissiondate'] = (int)($existing['admissiondate'] ?? 0);
                $profile['admissionstatus'] = (string)($existing['admissionstatus'] ?? 'pending');
                $profile['academicstatus'] = (string)($existing['academicstatus'] ?? 'active');
                $profile['expectedcompletion'] = (int)($existing['expectedcompletion'] ?? 0);
                $profile['status'] = (string)($existing['status'] ?? 'active');
                foreach (['entrytype', 'studymode', 'deliverymode', 'campus', 'intake', 'scholarship', 'sponsor'] as $key) {
                    $profile[$key] = (string)($existing[$key] ?? '');
                }
                $profile['timecreated'] = (int)($existing['timecreated'] ?? 0);
                $profile['timemodified'] = (int)($existing['timemodified'] ?? 0);
                $profile['usermodified'] = (int)($existing['usermodified'] ?? 0);
            }
        }

        return [
            'ok' => true,
            'plan' => [
                'userid' => $userid,
                'profile' => $profile,
                'blockers' => [],
                'canenrol' => $summary['unmapped'] === 0 && $summary['missing_moodle_course'] === 0,
                'items' => $items,
                'summary' => $summary,
                'electivegroups' => $groups,
                'electiveincompletegroups' => count($groups),
                'warning' => 'Admission can complete without Moodle enrolment when mappings are incomplete.',
            ],
        ];
    }

    /**
     * @return list<array{field:string,message:string}>
     */
    public static function enrolment_blockers(array $profile): array {
        $blockers = [];
        $status = (string)($profile['academicstatus'] ?? '');
        if (in_array($status, ['deferred', 'withdrawn', 'completed'], true)) {
            $blockers[] = [
                'field' => 'academicstatus',
                'message' => 'Student academic status does not allow automated Moodle Course enrolment (' . $status . ')',
            ];
        }
        if ((int)($profile['programmeid'] ?? 0) <= 0 || (int)($profile['curriculumid'] ?? 0) <= 0) {
            $blockers[] = ['field' => 'placement', 'message' => 'Academic placement is incomplete'];
        }
        if ((int)($profile['curriculumyearid'] ?? 0) <= 0) {
            $blockers[] = [
                'field' => 'curriculumyearid',
                'message' => 'Current Curriculum Year is incomplete',
            ];
        }
        // Curriculum semester is preferred for delivery, but auto-resolved when missing (see resolve_placement_gaps).
        if ((int)($profile['curriculumyearid'] ?? 0) > 0
            && (int)($profile['curriculumsemesterid'] ?? 0) <= 0) {
            $blockers[] = [
                'field' => 'curriculumsemesterid',
                'message' => 'Current Curriculum Semester is incomplete',
            ];
        }
        if ((int)($profile['academicyearid'] ?? 0) <= 0) {
            $blockers[] = ['field' => 'academicyearid', 'message' => 'Current Academic Year is missing'];
        }
        // Institutional semester is optional for placement completeness (calendar period may be unset).
        return $blockers;
    }

    /**
     * Fill missing curriculum / institutional semester IDs from catalogue defaults
     * and persist when the profile row exists. Used so students admitted without
     * explicit semester fields still get a complete delivery placement.
     *
     * @param array $profile
     * @return array Updated profile (possibly with new semester ids)
     */
    public static function resolve_placement_gaps(array $profile, bool $persist = true): array {
        global $DB, $USER;

        $userid = (int)($profile['userid'] ?? 0);
        $yearid = (int)($profile['curriculumyearid'] ?? 0);
        $semid = (int)($profile['curriculumsemesterid'] ?? 0);
        $ayid = (int)($profile['academicyearid'] ?? 0);
        $instsem = (int)($profile['semesterid'] ?? 0);
        $changed = false;

        if ($yearid > 0 && $semid <= 0 && $DB->get_manager()->table_exists('local_btiportal_curriculum_semester')) {
            $first = $DB->get_records(
                'local_btiportal_curriculum_semester',
                ['curriculumyearid' => $yearid],
                'semesternumber ASC, id ASC',
                '*',
                0,
                1
            );
            $row = $first ? reset($first) : false;
            if ($row) {
                $profile['curriculumsemesterid'] = (int)$row->id;
                $changed = true;
            }
        }

        if ($ayid > 0 && $instsem <= 0 && $DB->get_manager()->table_exists('local_btiportal_semester')) {
            $current = $DB->get_record('local_btiportal_semester', [
                'academicyearid' => $ayid,
                'iscurrent' => 1,
            ]);
            if (!$current) {
                $list = $DB->get_records(
                    'local_btiportal_semester',
                    ['academicyearid' => $ayid],
                    'sequence ASC, id ASC',
                    '*',
                    0,
                    1
                );
                $current = $list ? reset($list) : false;
            }
            if ($current) {
                $profile['semesterid'] = (int)$current->id;
                $changed = true;
            }
        }

        if ($persist && $changed && $userid > 0 && !empty($profile['id'])) {
            $update = (object)[
                'id' => (int)$profile['id'],
                'curriculumsemesterid' => (int)$profile['curriculumsemesterid'],
                'semesterid' => (int)$profile['semesterid'],
                'timemodified' => time(),
                'usermodified' => !empty($USER->id) ? (int)$USER->id : 0,
            ];
            $DB->update_record('local_btiportal_student_profile', $update);
        }

        return $profile;
    }

    public static function plan_structure_keys(): array {
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'profile' => new \core_external\external_single_structure(student_profile_helper::structure_keys()),
            'blockers' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'field' => new \core_external\external_value(PARAM_TEXT, 'field'),
                    'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
                ])
            ),
            'canenrol' => new \core_external\external_value(PARAM_BOOL, 'canenrol'),
            'items' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
                    'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
                    'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy'),
                    'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence'),
                    'credithours' => new \core_external\external_value(PARAM_INT, 'credithours', VALUE_OPTIONAL),
                    'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
                    'moodlecoursename' => new \core_external\external_value(PARAM_TEXT, 'moodlecoursename'),
                    'moodlecourseshortname' => new \core_external\external_value(PARAM_TEXT, 'moodlecourseshortname'),
                    'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
                    'enrolmentstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'enrolmentstatus'),
                    'enrolmentaction' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'enrolmentaction'),
                    'enrolmentid' => new \core_external\external_value(PARAM_INT, 'enrolmentid'),
                    'completion' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'completion'),
                    'electiveselected' => new \core_external\external_value(PARAM_INT, 'electiveselected', VALUE_OPTIONAL),
                ])
            ),
            'summary' => new \core_external\external_single_structure([
                'expected' => new \core_external\external_value(PARAM_INT, 'expected'),
                'enrolled' => new \core_external\external_value(PARAM_INT, 'enrolled'),
                'not_enrolled' => new \core_external\external_value(PARAM_INT, 'not_enrolled'),
                'unmapped' => new \core_external\external_value(PARAM_INT, 'unmapped'),
                'missing_moodle_course' => new \core_external\external_value(PARAM_INT, 'missing_moodle_course'),
                'manual_review' => new \core_external\external_value(PARAM_INT, 'manual_review'),
                'completed' => new \core_external\external_value(PARAM_INT, 'completed'),
                'ended' => new \core_external\external_value(PARAM_INT, 'ended'),
                'elective_required' => new \core_external\external_value(PARAM_INT, 'elective_required', VALUE_OPTIONAL),
                'elective_selected' => new \core_external\external_value(PARAM_INT, 'elective_selected', VALUE_OPTIONAL),
            ]),
            'electivegroups' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(elective_helper::group_structure_keys()),
                'electivegroups',
                VALUE_OPTIONAL
            ),
            'electiveincompletegroups' => new \core_external\external_value(PARAM_INT, 'electiveincompletegroups', VALUE_OPTIONAL),
            'warning' => new \core_external\external_value(PARAM_TEXT, 'warning'),
        ];
    }
}
