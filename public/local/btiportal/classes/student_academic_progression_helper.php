<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin-controlled Student semester progression and year promotion.
 *
 * Uses live Curriculum structure (not blind integer increments).
 * Same Moodle user throughout; history via student_lifecycle_helper patterns.
 */
class student_academic_progression_helper {
    /**
     * @return array{ok:bool,preview?:array,errors?:list}
     */
    public static function preview_semester_progression(int $userid, array $target = []): array {
        return self::preview($userid, 'semester_progression', $target);
    }

    /**
     * @return array{ok:bool,preview?:array,errors?:list}
     */
    public static function preview_promotion(int $userid, array $target = []): array {
        return self::preview($userid, 'promotion', $target);
    }

    /**
     * @return array{ok:bool,result?:array,errors?:list}
     */
    public static function progress_semester(int $userid, array $target, int $actorid = 0, bool $enrol = false): array {
        return self::execute($userid, 'semester_progression', $target, $actorid, $enrol);
    }

    /**
     * @return array{ok:bool,result?:array,errors?:list}
     */
    public static function promote(int $userid, array $target, int $actorid = 0, bool $enrol = false): array {
        return self::execute($userid, 'promotion', $target, $actorid, $enrol);
    }

    /**
     * @return array{ok:bool,preview?:array,errors?:list}
     */
    private static function preview(int $userid, string $actiontype, array $target): array {
        global $DB;

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Student academic profile not found']]];
        }
        if ((string)$profile['academicstatus'] !== 'active') {
            return ['ok' => false, 'errors' => [[
                'field' => 'academicstatus',
                'message' => 'Only active students can progress. Academic eligibility is subject to institutional approval.',
            ]]];
        }

        $resolved = self::resolve_target($profile, $actiontype, $target);
        if (!$resolved['ok']) {
            return $resolved;
        }

        $plan = student_delivery_plan_helper::get_plan($userid);
        $targetplanitems = curriculum_delivery_helper::list_course_units_for_placement(
            (int)$resolved['target']['curriculumid'],
            (int)$resolved['target']['curriculumyearid'],
            (int)$resolved['target']['curriculumsemesterid']
        );

        $eligibility = progression_eligibility_helper::assess($userid, $actiontype);
        if (!empty($eligibility['ok']) && !empty($eligibility['eligibility'])) {
            $elig = progression_eligibility_helper::sanitize_for_external($eligibility['eligibility']);
        } else {
            $elig = progression_eligibility_helper::empty_eligibility(
                $userid,
                $actiontype,
                'Automatic academic eligibility is not configured. Progression requires Admin approval.'
            );
        }

        return [
            'ok' => true,
            'preview' => [
                'userid' => $userid,
                'actiontype' => $actiontype,
                'current' => [
                    'curriculumyearid' => (int)$profile['curriculumyearid'],
                    'curriculumsemesterid' => (int)$profile['curriculumsemesterid'],
                    'academicyearid' => (int)$profile['academicyearid'],
                    'semesterid' => (int)$profile['semesterid'],
                    'programmeid' => (int)$profile['programmeid'],
                    'curriculumid' => (int)$profile['curriculumid'],
                    'academicstatus' => (string)$profile['academicstatus'],
                ],
                'target' => $resolved['target'],
                'notice' => (string)($elig['notice'] ?? 'Academic eligibility is subject to institutional approval.'),
                'eligibility' => $elig,
                'targetcourseunits' => array_map(static function ($u) {
                    return [
                        'courseunitid' => (int)$u['id'],
                        'code' => (string)$u['code'],
                        'name' => (string)$u['name'],
                        'moodlecourseid' => (int)($u['moodlecourseid'] ?? 0),
                        'mappingstatus' => (string)($u['mappingstatus'] ?? 'unmapped'),
                        'requirementtype' => (string)($u['requirementtype'] ?? 'required'),
                    ];
                }, $targetplanitems),
                'currentplansummary' => $plan['ok'] ? $plan['plan']['summary'] : null,
            ],
        ];
    }

    /**
     * @return array{ok:bool,result?:array,errors?:list}
     */
    private static function execute(
        int $userid,
        string $actiontype,
        array $target,
        int $actorid,
        bool $enrol
    ): array {
        $preview = self::preview($userid, $actiontype, $target);
        if (!$preview['ok']) {
            return $preview;
        }

        $t = $preview['preview']['target'];
        $input = [
            'userid' => $userid,
            'schoolid' => (int)($t['schoolid'] ?? 0),
            'departmentid' => (int)($t['departmentid'] ?? 0),
            'programmeid' => (int)$t['programmeid'],
            'curriculumid' => (int)$t['curriculumid'],
            'curriculumyearid' => (int)$t['curriculumyearid'],
            'curriculumsemesterid' => (int)$t['curriculumsemesterid'],
            'academicyearid' => (int)$t['academicyearid'],
            'semesterid' => (int)$t['semesterid'],
            'effectivedate' => (int)($target['effectivedate'] ?? time()),
            'reason' => (string)($target['reason'] ?? ($actiontype === 'promotion'
                ? 'Student year promotion'
                : 'Student semester progression')),
            'notes' => (string)($target['notes'] ?? ''),
            'enrolmentpolicy' => 'preserve',
        ];

        // Use lifecycle placement update with correct action type via dedicated path.
        $result = self::apply_progression($actiontype, $input, $actorid);
        if (!$result['ok']) {
            return $result;
        }

        $enrolmentresult = null;
        if ($enrol) {
            $optype = $actiontype === 'promotion'
                ? 'year_progression_enrolment'
                : 'semester_progression_enrolment';
            $enrolment = academic_enrolment_helper::execute($userid, $optype, [], $actorid);
            $enrolmentresult = $enrolment['ok'] ? $enrolment['result'] : [
                'status' => 'failed',
                'errors' => $enrolment['errors'] ?? [],
            ];
        }

        $plan = student_delivery_plan_helper::get_plan($userid);

        return [
            'ok' => true,
            'result' => [
                'moodleuserid' => $userid,
                'actiontype' => $actiontype,
                'profile' => $result['profile'],
                'history' => $result['history'],
                'previous' => $result['previous'],
                'enrolment' => $result['enrolment'],
                'academicenrolment' => $enrolmentresult,
                'plan' => $plan['ok'] ? $plan['plan'] : null,
                'notice' => 'Academic eligibility is subject to institutional approval.',
            ],
        ];
    }

    /**
     * Run progression using student_lifecycle_helper::update_placement but with
     * the correct lifecycle action type recorded on history.
     */
    private static function apply_progression(string $actiontype, array $input, int $actorid): array {
        // Temporarily piggy-back on lifecycle by calling transfer-like path for history typing.
        // Use a dedicated internal call mirroring update_placement with custom actiontype.
        return self::run_with_action($actiontype, $input, $actorid);
    }

    /**
     * Mirrors student_lifecycle_helper transaction with a specific action type.
     */
    private static function run_with_action(string $actiontype, array $input, int $actorid): array {
        global $DB;

        $userid = (int)$input['userid'];
        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Student academic profile not found']]];
        }

        $patch = [];
        foreach ([
            'schoolid', 'departmentid', 'programmeid', 'curriculumid',
            'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid',
        ] as $field) {
            if (array_key_exists($field, $input) && (int)$input[$field] > 0) {
                $patch[$field] = (int)$input[$field];
            }
        }
        $patch['academicstatus'] = 'active';

        $previous = student_academic_history_helper::snapshot_from_profile($profile);
        $transaction = $DB->start_delegated_transaction();
        try {
            $update = student_profile_helper::update((int)$profile['id'], $patch, $actorid);
            if (!$update['ok']) {
                throw new \Exception($update['errors'][0]['message'] ?? 'Profile update failed');
            }
            $updated = $update['record'];

            $history = student_academic_history_helper::create(array_merge(
                [
                    'userid' => $userid,
                    'actiontype' => $actiontype,
                    'approvalstatus' => 'recorded',
                    'approvedby' => $actorid,
                    'enrolmentpolicy' => 'preserve',
                    'enrolmentresult' => '',
                    'effectivedate' => (int)($input['effectivedate'] ?? time()),
                    'reason' => (string)($input['reason'] ?? ''),
                    'notes' => (string)($input['notes'] ?? ''),
                ],
                $previous,
                student_academic_history_helper::snapshot_new_from_profile($updated)
            ), $actorid);
            if (!$history['ok']) {
                throw new \Exception($history['errors'][0]['message'] ?? 'History insert failed');
            }

            $enrolment = enrolment_transition_helper::apply($userid, 'preserve', [], []);
            if (!$enrolment['ok']) {
                throw new \Exception($enrolment['errors'][0]['message'] ?? 'Enrolment transition failed');
            }

            $historyrow = $DB->get_record(
                student_academic_history_helper::TABLE,
                ['id' => (int)$history['record']['id']]
            );
            if ($historyrow) {
                $historyrow->enrolmentresult = json_encode($enrolment['result']);
                $historyrow->timemodified = time();
                $DB->update_record(student_academic_history_helper::TABLE, $historyrow);
                $history['record'] = student_academic_history_helper::format($historyrow);
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        return [
            'ok' => true,
            'profile' => $updated,
            'history' => $history['record'],
            'previous' => $previous,
            'enrolment' => $enrolment['result'],
        ];
    }

    /**
     * Resolve next semester or next year from live curriculum structure.
     *
     * @return array{ok:bool,target?:array,errors?:list}
     */
    private static function resolve_target(array $profile, string $actiontype, array $target): array {
        global $DB;

        $curriculumid = (int)$profile['curriculumid'];
        $yearid = (int)$profile['curriculumyearid'];
        $semid = (int)$profile['curriculumsemesterid'];

        if ($curriculumid <= 0 || $yearid <= 0 || $semid <= 0) {
            return ['ok' => false, 'errors' => [[
                'field' => 'placement',
                'message' => 'Current Curriculum Year and Curriculum Semester are required',
            ]]];
        }

        $year = $DB->get_record('local_btiportal_curriculum_year', ['id' => $yearid]);
        $sem = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $semid]);
        if (!$year || !$sem || (int)$year->curriculumid !== $curriculumid
            || (int)$sem->curriculumyearid !== $yearid) {
            return ['ok' => false, 'errors' => [[
                'field' => 'placement',
                'message' => 'Current Curriculum placement is invalid',
            ]]];
        }

        $targetyearid = $yearid;
        $targetsemid = 0;
        $targetyear = $year;

        if ($actiontype === 'semester_progression') {
            if (!empty($target['curriculumsemesterid'])) {
                $targetsemid = (int)$target['curriculumsemesterid'];
            } else {
                $next = $DB->get_record_sql(
                    "SELECT * FROM {local_btiportal_curriculum_semester}
                      WHERE curriculumyearid = ? AND semesternumber > ? AND status <> 'archived'
                   ORDER BY semesternumber ASC",
                    [$yearid, (int)$sem->semesternumber],
                    IGNORE_MULTIPLE
                );
                if ($next) {
                    $targetsemid = (int)$next->id;
                } else {
                    // Cross into first semester of next year if available.
                    $nextyear = $DB->get_record_sql(
                        "SELECT * FROM {local_btiportal_curriculum_year}
                          WHERE curriculumid = ? AND yearnumber > ? AND status <> 'archived'
                       ORDER BY yearnumber ASC",
                        [$curriculumid, (int)$year->yearnumber],
                        IGNORE_MULTIPLE
                    );
                    if (!$nextyear) {
                        return ['ok' => false, 'errors' => [[
                            'field' => 'curriculumsemesterid',
                            'message' => 'No next Curriculum Semester exists in the live Curriculum structure',
                        ]]];
                    }
                    $targetyear = $nextyear;
                    $targetyearid = (int)$nextyear->id;
                    $firstsem = $DB->get_record_sql(
                        "SELECT * FROM {local_btiportal_curriculum_semester}
                          WHERE curriculumyearid = ? AND status <> 'archived'
                       ORDER BY semesternumber ASC",
                        [$targetyearid],
                        IGNORE_MULTIPLE
                    );
                    if (!$firstsem) {
                        return ['ok' => false, 'errors' => [[
                            'field' => 'curriculumsemesterid',
                            'message' => 'Next Curriculum Year has no Curriculum Semesters',
                        ]]];
                    }
                    $targetsemid = (int)$firstsem->id;
                }
            }
        } else {
            // promotion
            if (!empty($target['curriculumyearid'])) {
                $targetyearid = (int)$target['curriculumyearid'];
                $targetyear = $DB->get_record('local_btiportal_curriculum_year', ['id' => $targetyearid]);
            } else {
                $targetyear = $DB->get_record_sql(
                    "SELECT * FROM {local_btiportal_curriculum_year}
                      WHERE curriculumid = ? AND yearnumber > ? AND status <> 'archived'
                   ORDER BY yearnumber ASC",
                    [$curriculumid, (int)$year->yearnumber],
                    IGNORE_MULTIPLE
                );
                $targetyearid = $targetyear ? (int)$targetyear->id : 0;
            }
            if (!$targetyear || (int)$targetyear->curriculumid !== $curriculumid) {
                return ['ok' => false, 'errors' => [[
                    'field' => 'curriculumyearid',
                    'message' => 'No valid next Curriculum Year exists in the live Curriculum structure',
                ]]];
            }
            if (!empty($target['curriculumsemesterid'])) {
                $targetsemid = (int)$target['curriculumsemesterid'];
            } else {
                $firstsem = $DB->get_record_sql(
                    "SELECT * FROM {local_btiportal_curriculum_semester}
                      WHERE curriculumyearid = ? AND status <> 'archived'
                   ORDER BY semesternumber ASC",
                    [$targetyearid],
                    IGNORE_MULTIPLE
                );
                $targetsemid = $firstsem ? (int)$firstsem->id : 0;
            }
        }

        $targetsem = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $targetsemid]);
        if (!$targetsem || (int)$targetsem->curriculumyearid !== $targetyearid) {
            return ['ok' => false, 'errors' => [[
                'field' => 'curriculumsemesterid',
                'message' => 'Target Curriculum Semester is invalid for the target Curriculum Year',
            ]]];
        }

        $academicyearid = (int)($target['academicyearid'] ?? $profile['academicyearid']);
        $semesterid = (int)($target['semesterid'] ?? $profile['semesterid']);
        if ($academicyearid <= 0 || !$DB->record_exists('local_btiportal_academic_year', ['id' => $academicyearid])) {
            return ['ok' => false, 'errors' => [['field' => 'academicyearid', 'message' => 'Target Academic Year is required']]];
        }
        if ($semesterid <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'semesterid', 'message' => 'Target Institutional Semester is required']]];
        }
        $instsem = $DB->get_record('local_btiportal_semester', ['id' => $semesterid]);
        if (!$instsem || (int)$instsem->academicyearid !== $academicyearid) {
            return ['ok' => false, 'errors' => [[
                'field' => 'semesterid',
                'message' => 'Target Institutional Semester does not belong to the selected Academic Year',
            ]]];
        }

        return [
            'ok' => true,
            'target' => [
                'schoolid' => (int)$profile['schoolid'],
                'departmentid' => (int)$profile['departmentid'],
                'programmeid' => (int)$profile['programmeid'],
                'curriculumid' => $curriculumid,
                'curriculumyearid' => $targetyearid,
                'curriculumsemesterid' => $targetsemid,
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
                'yearnumber' => (int)$targetyear->yearnumber,
                'semesternumber' => (int)$targetsem->semesternumber,
                'curriculumyearname' => (string)$targetyear->name,
                'curriculumsemestername' => (string)$targetsem->name,
            ],
        ];
    }

    public static function preview_structure_keys(): array {
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'actiontype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'actiontype'),
            'current' => new \core_external\external_single_structure([
                'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
                'curriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'curriculumsemesterid'),
                'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
                'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
                'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
                'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
                'academicstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'academicstatus'),
            ]),
            'target' => new \core_external\external_single_structure([
                'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
                'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
                'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
                'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
                'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
                'curriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'curriculumsemesterid'),
                'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
                'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
                'yearnumber' => new \core_external\external_value(PARAM_INT, 'yearnumber'),
                'semesternumber' => new \core_external\external_value(PARAM_INT, 'semesternumber'),
                'curriculumyearname' => new \core_external\external_value(PARAM_TEXT, 'curriculumyearname'),
                'curriculumsemestername' => new \core_external\external_value(PARAM_TEXT, 'curriculumsemestername'),
            ]),
            'notice' => new \core_external\external_value(PARAM_TEXT, 'notice'),
            'eligibility' => new \core_external\external_single_structure(
                progression_eligibility_helper::eligibility_structure_keys(),
                'eligibility',
                VALUE_OPTIONAL
            ),
            'targetcourseunits' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
                    'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
                    'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
                    'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
                ])
            ),
            'currentplansummary' => new \core_external\external_single_structure([
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
            ], 'currentplansummary', VALUE_OPTIONAL),
        ];
    }

    public static function result_structure_keys(): array {
        return [
            'moodleuserid' => new \core_external\external_value(PARAM_INT, 'moodleuserid'),
            'actiontype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'actiontype'),
            'profile' => new \core_external\external_single_structure(student_profile_helper::structure_keys()),
            'history' => new \core_external\external_single_structure(student_academic_history_helper::structure_keys()),
            'previous' => new \core_external\external_single_structure([
                'prevschoolid' => new \core_external\external_value(PARAM_INT, 'prevschoolid'),
                'prevdepartmentid' => new \core_external\external_value(PARAM_INT, 'prevdepartmentid'),
                'prevprogrammeid' => new \core_external\external_value(PARAM_INT, 'prevprogrammeid'),
                'prevcurriculumid' => new \core_external\external_value(PARAM_INT, 'prevcurriculumid'),
                'prevcurriculumyearid' => new \core_external\external_value(PARAM_INT, 'prevcurriculumyearid'),
                'prevcurriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'prevcurriculumsemesterid'),
                'prevacademicyearid' => new \core_external\external_value(PARAM_INT, 'prevacademicyearid'),
                'prevsemesterid' => new \core_external\external_value(PARAM_INT, 'prevsemesterid'),
                'prevacademicstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'prevacademicstatus'),
            ]),
            'enrolment' => new \core_external\external_single_structure(enrolment_transition_helper::result_structure_keys()),
            'academicenrolment' => new \core_external\external_single_structure(
                academic_enrolment_helper::result_structure_keys(),
                'academicenrolment',
                VALUE_OPTIONAL
            ),
            'plan' => new \core_external\external_single_structure(
                student_delivery_plan_helper::plan_structure_keys(),
                'plan',
                VALUE_OPTIONAL
            ),
            'notice' => new \core_external\external_value(PARAM_TEXT, 'notice'),
        ];
    }
}
