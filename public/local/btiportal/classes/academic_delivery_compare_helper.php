<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Compare current vs target academic delivery and classify Moodle enrolment actions.
 *
 * Reuses delivery plan + Moodle enrolments. Does not delete grades or history.
 */
class academic_delivery_compare_helper {
    /**
     * Compare a student's current placement delivery against a target placement.
     *
     * @param array $targetplacement schoolid, departmentid, programmeid, curriculumid,
     *        curriculumyearid, curriculumsemesterid, academicyearid, semesterid
     * @return array{ok:bool,comparison?:array,errors?:list}
     */
    public static function compare(int $userid, array $targetplacement, string $policy = 'preserve_and_enrol_new'): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }
        if (!in_array($policy, academic_constants::DELIVERY_TRANSITION_POLICIES, true)) {
            return ['ok' => false, 'errors' => [['field' => 'policy', 'message' => 'Invalid delivery transition policy']]];
        }

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Student academic profile not found']]];
        }

        $targeterrors = self::validate_target($targetplacement);
        if ($targeterrors) {
            return ['ok' => false, 'errors' => $targeterrors];
        }

        $currentplan = student_delivery_plan_helper::get_plan($userid);
        $targetunits = curriculum_delivery_helper::list_course_units_for_placement(
            (int)$targetplacement['curriculumid'],
            (int)$targetplacement['curriculumyearid'],
            (int)$targetplacement['curriculumsemesterid']
        );
        $selected = elective_helper::selected_courseunit_set(
            $userid,
            (int)($targetplacement['academicyearid'] ?? $profile['academicyearid'] ?? 0),
            (int)($targetplacement['semesterid'] ?? $profile['semesterid'] ?? 0)
        );

        $enrolments = enrolment_transition_helper::list_user_enrolments($userid);
        $enrolbycourse = [];
        foreach ($enrolments as $e) {
            $cid = (int)$e['courseid'];
            if (!isset($enrolbycourse[$cid]) || (int)$e['status'] === 0) {
                $enrolbycourse[$cid] = $e;
            }
        }

        $currentcourses = [];
        if ($currentplan['ok']) {
            foreach ($currentplan['plan']['items'] as $item) {
                $cid = (int)$item['moodlecourseid'];
                if ($cid > 1) {
                    $currentcourses[$cid] = $item;
                }
            }
        }

        $targetcourses = [];
        $targetitems = [];
        foreach ($targetunits as $unit) {
            $req = (string)($unit['requirementtype'] ?? 'required');
            $cuid = (int)$unit['id'];
            $cid = (int)($unit['moodlecourseid'] ?? $unit['courseid'] ?? 0);
            $map = (string)($unit['mappingstatus'] ?? 'unmapped');
            $needed = ($req === 'required') || ($req === 'elective' && isset($selected[$cuid]));
            $row = [
                'courseunitid' => $cuid,
                'code' => (string)$unit['code'],
                'name' => (string)$unit['name'],
                'requirementtype' => $req,
                'moodlecourseid' => $cid,
                'moodlecoursename' => (string)($unit['moodlecoursename'] ?? ''),
                'mappingstatus' => $map,
                'needed' => $needed ? 1 : 0,
            ];
            $targetitems[] = $row;
            if ($needed && $cid > 1) {
                $targetcourses[$cid] = $row;
            }
        }

        $diff = [];
        $seen = [];

        // Target required/selected courses.
        foreach ($targetitems as $row) {
            $cid = (int)$row['moodlecourseid'];
            $action = 'manual_review';
            $reason = '';
            if ((int)$row['needed'] !== 1) {
                if ($row['requirementtype'] === 'elective') {
                    $action = 'elective_decision_required';
                    $reason = 'Elective not selected';
                } else {
                    continue;
                }
            } else if ($row['mappingstatus'] === 'unmapped' || $cid <= 1) {
                $action = 'unmapped';
                $reason = 'Target Course Unit has no Moodle Course mapping';
            } else if ($row['mappingstatus'] === 'missing_moodle_course') {
                $action = 'unmapped';
                $reason = 'Mapped Moodle Course is missing';
            } else if (isset($enrolbycourse[$cid]) && (int)$enrolbycourse[$cid]['status'] === 0) {
                $action = 'already_enrolled';
                $reason = 'Already enrolled in target Moodle Course';
            } else if (isset($enrolbycourse[$cid])) {
                $action = 'add';
                $reason = 'Previous enrolment ended; re-enrol required';
            } else {
                $action = 'add';
                $reason = 'New required Moodle Course for target placement';
            }
            if ($policy === 'manual_review_only' && in_array($action, ['add'], true)) {
                $action = 'manual_review';
                $reason = 'Manual review only policy';
            }
            $diff[] = array_merge($row, ['action' => $action, 'reason' => $reason]);
            if ($cid > 1) {
                $seen[$cid] = true;
            }
        }

        // Current enrolments not in target needed set.
        foreach ($enrolbycourse as $cid => $enrol) {
            if (isset($seen[$cid])) {
                continue;
            }
            $active = (int)$enrol['status'] === 0;
            $action = 'retain';
            $reason = 'Preserve existing Moodle enrolment / history';
            if ($policy === 'preserve_and_enrol_new' || $policy === 'enrol_new_only' || $policy === 'manual_review_only') {
                $action = 'retain';
                $reason = 'Existing enrolment retained; grades and history preserved';
            } else if ($policy === 'end_active_and_enrol_new' && $active) {
                $action = 'end_active';
                $reason = 'End active enrolment for courses no longer required';
            } else if ($policy === 'unenrol_previous_and_enrol_new' && $active) {
                $action = 'unenrol';
                $reason = 'Hard unenrol previous active courses (explicit Admin policy)';
            }
            $diff[] = [
                'courseunitid' => (int)($currentcourses[$cid]['courseunitid'] ?? 0),
                'code' => (string)($currentcourses[$cid]['code'] ?? ''),
                'name' => (string)($currentcourses[$cid]['name'] ?? ($enrol['fullname'] ?? '')),
                'requirementtype' => (string)($currentcourses[$cid]['requirementtype'] ?? ''),
                'moodlecourseid' => $cid,
                'moodlecoursename' => (string)($enrol['fullname'] ?? ''),
                'mappingstatus' => 'mapped',
                'needed' => 0,
                'action' => $action,
                'reason' => $reason,
                'completed_history_retained' => 1,
                'grade_history_retained' => 1,
            ];
        }

        $buckets = [
            'retain' => [],
            'already_enrolled' => [],
            'add' => [],
            'end_active' => [],
            'unenrol' => [],
            'manual_review' => [],
            'unmapped' => [],
            'elective_decision_required' => [],
        ];
        foreach ($diff as $row) {
            $a = $row['action'];
            if (!isset($buckets[$a])) {
                $buckets[$a] = [];
            }
            $buckets[$a][] = $row;
        }

        $destructive = in_array($policy, ['end_active_and_enrol_new', 'unenrol_previous_and_enrol_new'], true);

        return [
            'ok' => true,
            'comparison' => [
                'userid' => $userid,
                'policy' => $policy,
                'destructive' => $destructive ? 1 : 0,
                'defaultpolicy' => 'preserve_and_enrol_new',
                'warnings' => [
                    'Existing Moodle grades will not be deleted.',
                    'Completed Course history will be preserved.',
                    'The Student Moodle account will remain unchanged.',
                    'Hard unenrolment is never the default and requires explicit confirmation.',
                ],
                'current' => [
                    'schoolid' => (int)$profile['schoolid'],
                    'departmentid' => (int)$profile['departmentid'],
                    'programmeid' => (int)$profile['programmeid'],
                    'curriculumid' => (int)$profile['curriculumid'],
                    'curriculumyearid' => (int)$profile['curriculumyearid'],
                    'curriculumsemesterid' => (int)$profile['curriculumsemesterid'],
                    'academicyearid' => (int)$profile['academicyearid'],
                    'semesterid' => (int)$profile['semesterid'],
                    'courseunits' => $currentplan['ok'] ? $currentplan['plan']['items'] : [],
                    'enrolments' => $enrolments,
                ],
                'target' => [
                    'schoolid' => (int)($targetplacement['schoolid'] ?? $profile['schoolid']),
                    'departmentid' => (int)($targetplacement['departmentid'] ?? $profile['departmentid']),
                    'programmeid' => (int)$targetplacement['programmeid'],
                    'curriculumid' => (int)$targetplacement['curriculumid'],
                    'curriculumyearid' => (int)$targetplacement['curriculumyearid'],
                    'curriculumsemesterid' => (int)$targetplacement['curriculumsemesterid'],
                    'academicyearid' => (int)($targetplacement['academicyearid'] ?? 0),
                    'semesterid' => (int)($targetplacement['semesterid'] ?? 0),
                    'courseunits' => $targetitems,
                ],
                'difference' => $diff,
                'buckets' => $buckets,
                'summary' => [
                    'retain' => count($buckets['retain']),
                    'already_enrolled' => count($buckets['already_enrolled']),
                    'add' => count($buckets['add']),
                    'end_active' => count($buckets['end_active']),
                    'unenrol' => count($buckets['unenrol']),
                    'manual_review' => count($buckets['manual_review']),
                    'unmapped' => count($buckets['unmapped']),
                    'elective_decision_required' => count($buckets['elective_decision_required']),
                ],
            ],
        ];
    }

    /**
     * @return list<array{field:string,message:string}>
     */
    private static function validate_target(array $target): array {
        global $DB;
        $errors = [];
        foreach (['programmeid', 'curriculumid', 'curriculumyearid', 'curriculumsemesterid'] as $field) {
            if ((int)($target[$field] ?? 0) <= 0) {
                $errors[] = ['field' => $field, 'message' => 'Target ' . $field . ' is required'];
            }
        }
        if ($errors) {
            return $errors;
        }
        if (!$DB->record_exists('local_btiportal_programme', ['id' => (int)$target['programmeid']])) {
            $errors[] = ['field' => 'programmeid', 'message' => 'Target programme not found'];
        }
        $curriculum = $DB->get_record('local_btiportal_curriculum', ['id' => (int)$target['curriculumid']]);
        if (!$curriculum) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Target curriculum not found'];
        } else if ((int)$curriculum->programmeid !== (int)$target['programmeid']) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Target curriculum does not belong to programme'];
        }
        $year = $DB->get_record('local_btiportal_curriculum_year', ['id' => (int)$target['curriculumyearid']]);
        if (!$year || (int)$year->curriculumid !== (int)$target['curriculumid']) {
            $errors[] = ['field' => 'curriculumyearid', 'message' => 'Target curriculum year invalid'];
        }
        $sem = $DB->get_record('local_btiportal_curriculum_semester', ['id' => (int)$target['curriculumsemesterid']]);
        if (!$sem || (int)$sem->curriculumyearid !== (int)$target['curriculumyearid']) {
            $errors[] = ['field' => 'curriculumsemesterid', 'message' => 'Target curriculum semester invalid'];
        }
        return $errors;
    }

    public static function comparison_structure_keys(): array {
        $item = [
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'moodlecoursename' => new \core_external\external_value(PARAM_TEXT, 'moodlecoursename'),
            'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
            'needed' => new \core_external\external_value(PARAM_INT, 'needed', VALUE_OPTIONAL),
            'action' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'action', VALUE_OPTIONAL),
            'reason' => new \core_external\external_value(PARAM_TEXT, 'reason', VALUE_OPTIONAL),
            'completed_history_retained' => new \core_external\external_value(PARAM_INT, 'completed_history_retained', VALUE_OPTIONAL),
            'grade_history_retained' => new \core_external\external_value(PARAM_INT, 'grade_history_retained', VALUE_OPTIONAL),
            'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy', VALUE_OPTIONAL),
            'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence', VALUE_OPTIONAL),
            'moodlecourseshortname' => new \core_external\external_value(PARAM_TEXT, 'moodlecourseshortname', VALUE_OPTIONAL),
            'enrolmentstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'enrolmentstatus', VALUE_OPTIONAL),
            'enrolmentaction' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'enrolmentaction', VALUE_OPTIONAL),
            'enrolmentid' => new \core_external\external_value(PARAM_INT, 'enrolmentid', VALUE_OPTIONAL),
            'completion' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'completion', VALUE_OPTIONAL),
        ];
        $bucket = new \core_external\external_multiple_structure(
            new \core_external\external_single_structure($item)
        );
        $placement = [
            'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
            'curriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'curriculumsemesterid'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'courseunits' => $bucket,
            'enrolments' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'enrolmentid' => new \core_external\external_value(PARAM_INT, 'enrolmentid'),
                    'courseid' => new \core_external\external_value(PARAM_INT, 'courseid'),
                    'fullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
                    'shortname' => new \core_external\external_value(PARAM_TEXT, 'shortname'),
                    'visible' => new \core_external\external_value(PARAM_INT, 'visible'),
                    'enrol' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'enrol'),
                    'status' => new \core_external\external_value(PARAM_INT, 'status'),
                    'statuslabel' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'statuslabel'),
                    'role' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'role'),
                    'timestart' => new \core_external\external_value(PARAM_INT, 'timestart'),
                    'timeend' => new \core_external\external_value(PARAM_INT, 'timeend'),
                    'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
                    'completion' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'completion'),
                ]),
                'enrolments',
                VALUE_OPTIONAL
            ),
        ];
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'policy' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'policy'),
            'destructive' => new \core_external\external_value(PARAM_INT, 'destructive'),
            'defaultpolicy' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'defaultpolicy'),
            'warnings' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_TEXT, 'warning')
            ),
            'current' => new \core_external\external_single_structure($placement),
            'target' => new \core_external\external_single_structure([
                'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
                'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
                'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
                'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
                'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
                'curriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'curriculumsemesterid'),
                'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
                'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
                'courseunits' => $bucket,
            ]),
            'difference' => $bucket,
            'buckets' => new \core_external\external_single_structure([
                'retain' => $bucket,
                'already_enrolled' => $bucket,
                'add' => $bucket,
                'end_active' => $bucket,
                'unenrol' => $bucket,
                'manual_review' => $bucket,
                'unmapped' => $bucket,
                'elective_decision_required' => $bucket,
            ]),
            'summary' => new \core_external\external_single_structure([
                'retain' => new \core_external\external_value(PARAM_INT, 'retain'),
                'already_enrolled' => new \core_external\external_value(PARAM_INT, 'already_enrolled'),
                'add' => new \core_external\external_value(PARAM_INT, 'add'),
                'end_active' => new \core_external\external_value(PARAM_INT, 'end_active'),
                'unenrol' => new \core_external\external_value(PARAM_INT, 'unenrol'),
                'manual_review' => new \core_external\external_value(PARAM_INT, 'manual_review'),
                'unmapped' => new \core_external\external_value(PARAM_INT, 'unmapped'),
                'elective_decision_required' => new \core_external\external_value(PARAM_INT, 'elective_decision_required'),
            ]),
        ];
    }
}
