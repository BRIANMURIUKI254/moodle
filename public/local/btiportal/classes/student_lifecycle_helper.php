<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Student academic lifecycle mutations.
 *
 * Always keyed on the existing Moodle user id. Never creates or deletes Moodle users.
 * Current placement stays in local_btiportal_student_profile; history is separate.
 */
class student_lifecycle_helper {
    /**
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,errors?:list}
     */
    public static function defer(array $input, int $actorid = 0): array {
        return self::run_lifecycle('deferral', $input, $actorid, function (array $profile, array $input) {
            $errors = [];
            $status = (string)$profile['academicstatus'];
            if ($status === 'deferred') {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Student already has an active deferral'];
            }
            if ($status === 'completed') {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Graduated students cannot be deferred'];
            }
            if ($status === 'withdrawn') {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Withdrawn students cannot be deferred'];
            }
            if (!in_array($status, ['active', 'probation', 'suspended'], true) && !$errors) {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Student does not have a valid active academic placement'];
            }

            $effectivedate = (int)($input['effectivedate'] ?? 0);
            $expectedreturn = (int)($input['expectedreturndate'] ?? 0);
            $reason = trim((string)($input['reason'] ?? ''));
            if ($effectivedate <= 0) {
                $errors[] = ['field' => 'effectivedate', 'message' => 'Effective deferral date is required'];
            }
            if ($expectedreturn <= 0) {
                $errors[] = ['field' => 'expectedreturndate', 'message' => 'Expected return date is required'];
            } else if ($effectivedate > 0 && $expectedreturn <= $effectivedate) {
                $errors[] = ['field' => 'expectedreturndate', 'message' => 'Expected return date must be after the deferral date'];
            }
            if ($reason === '') {
                $errors[] = ['field' => 'reason', 'message' => 'Deferral reason is required'];
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }

            return [
                'ok' => true,
                'profilepatch' => [
                    'academicstatus' => 'deferred',
                    'admissionstatus' => 'deferred',
                ],
                'historyextra' => [
                    'effectivedate' => $effectivedate,
                    'expectedreturndate' => $expectedreturn,
                    'reason' => $reason,
                    'notes' => (string)($input['notes'] ?? ''),
                ],
            ];
        });
    }

    /**
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,errors?:list}
     */
    public static function return_from_deferral(array $input, int $actorid = 0): array {
        return self::run_lifecycle('return_from_deferral', $input, $actorid, function (array $profile, array $input) {
            $errors = [];
            if ((string)$profile['academicstatus'] !== 'deferred') {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Student is not currently deferred'];
            }
            $returndate = (int)($input['returndate'] ?? $input['effectivedate'] ?? 0);
            if ($returndate <= 0) {
                $errors[] = ['field' => 'returndate', 'message' => 'Return date is required'];
            }
            $academicyearid = (int)($input['academicyearid'] ?? 0);
            $semesterid = (int)($input['semesterid'] ?? 0);
            if ($academicyearid <= 0) {
                $errors[] = ['field' => 'academicyearid', 'message' => 'Academic year is required'];
            }
            if ($semesterid <= 0) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Institutional semester is required'];
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }

            $patch = [
                'academicstatus' => 'active',
                'admissionstatus' => 'active',
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
            ];
            foreach (['curriculumyearid', 'curriculumsemesterid', 'programmeid', 'curriculumid',
                'schoolid', 'departmentid'] as $optional) {
                if (array_key_exists($optional, $input) && (int)$input[$optional] > 0) {
                    $patch[$optional] = (int)$input[$optional];
                }
            }

            return [
                'ok' => true,
                'profilepatch' => $patch,
                'historyextra' => [
                    'effectivedate' => $returndate,
                    'reason' => (string)($input['reason'] ?? ''),
                    'notes' => (string)($input['notes'] ?? ''),
                ],
            ];
        });
    }

    /**
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,errors?:list}
     */
    public static function transfer_programme(array $input, int $actorid = 0, string $actiontype = 'programme_transfer'): array {
        if (!in_array($actiontype, ['programme_transfer', 'programme_change'], true)) {
            $actiontype = 'programme_transfer';
        }
        return self::run_lifecycle($actiontype, $input, $actorid, function (array $profile, array $input) {
            $errors = [];
            $status = (string)$profile['academicstatus'];
            if (in_array($status, ['withdrawn', 'completed'], true)) {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Cannot transfer a withdrawn or graduated student'];
            }
            $effectivedate = (int)($input['effectivedate'] ?? 0);
            $reason = trim((string)($input['reason'] ?? ''));
            if ($effectivedate <= 0) {
                $errors[] = ['field' => 'effectivedate', 'message' => 'Effective transfer date is required'];
            }
            if ($reason === '') {
                $errors[] = ['field' => 'reason', 'message' => 'Transfer reason is required'];
            }

            $required = ['schoolid', 'departmentid', 'programmeid', 'curriculumid'];
            foreach ($required as $field) {
                if ((int)($input[$field] ?? 0) <= 0) {
                    $errors[] = ['field' => $field, 'message' => ucfirst($field) . ' is required'];
                }
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }

            $patch = [
                'schoolid' => (int)$input['schoolid'],
                'departmentid' => (int)$input['departmentid'],
                'programmeid' => (int)$input['programmeid'],
                'curriculumid' => (int)$input['curriculumid'],
                'academicstatus' => 'active',
                'admissionstatus' => (string)($profile['admissionstatus'] === 'deferred' ? 'active' : $profile['admissionstatus']),
            ];
            foreach (['curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid'] as $optional) {
                if (array_key_exists($optional, $input)) {
                    $patch[$optional] = (int)$input[$optional];
                }
            }

            return [
                'ok' => true,
                'profilepatch' => $patch,
                'historyextra' => [
                    'effectivedate' => $effectivedate,
                    'reason' => $reason,
                    'notes' => (string)($input['notes'] ?? ''),
                ],
            ];
        });
    }

    /**
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,errors?:list}
     */
    public static function change_programme(array $input, int $actorid = 0): array {
        return self::transfer_programme($input, $actorid, 'programme_change');
    }

    /**
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,errors?:list}
     */
    public static function withdraw(array $input, int $actorid = 0): array {
        return self::run_lifecycle('withdrawal', $input, $actorid, function (array $profile, array $input) {
            $errors = [];
            if ((string)$profile['academicstatus'] === 'withdrawn') {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Student is already withdrawn'];
            }
            if ((string)$profile['academicstatus'] === 'completed') {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Graduated students cannot be withdrawn'];
            }
            $effectivedate = (int)($input['effectivedate'] ?? 0);
            $reason = trim((string)($input['reason'] ?? ''));
            if ($effectivedate <= 0) {
                $errors[] = ['field' => 'effectivedate', 'message' => 'Effective withdrawal date is required'];
            }
            if ($reason === '') {
                $errors[] = ['field' => 'reason', 'message' => 'Withdrawal reason is required'];
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }
            return [
                'ok' => true,
                'profilepatch' => [
                    'academicstatus' => 'withdrawn',
                    'admissionstatus' => 'withdrawn',
                ],
                'historyextra' => [
                    'effectivedate' => $effectivedate,
                    'reason' => $reason,
                    'notes' => (string)($input['notes'] ?? ''),
                ],
            ];
        });
    }

    /**
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,errors?:list}
     */
    public static function readmit(array $input, int $actorid = 0): array {
        return self::run_lifecycle('readmission', $input, $actorid, function (array $profile, array $input) {
            $errors = [];
            $status = (string)$profile['academicstatus'];
            if (!in_array($status, ['withdrawn', 'suspended'], true)
                && (string)$profile['status'] !== 'inactive') {
                $errors[] = [
                    'field' => 'academicstatus',
                    'message' => 'Readmission is only available for withdrawn or inactive students',
                ];
            }
            $effectivedate = (int)($input['effectivedate'] ?? $input['readmissiondate'] ?? 0);
            if ($effectivedate <= 0) {
                $errors[] = ['field' => 'effectivedate', 'message' => 'Readmission date is required'];
            }
            $academicyearid = (int)($input['academicyearid'] ?? 0);
            $semesterid = (int)($input['semesterid'] ?? 0);
            if ($academicyearid <= 0) {
                $errors[] = ['field' => 'academicyearid', 'message' => 'Academic year is required'];
            }
            if ($semesterid <= 0) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Institutional semester is required'];
            }
            $reason = trim((string)($input['reason'] ?? ''));
            if ($reason === '') {
                $errors[] = ['field' => 'reason', 'message' => 'Reason or approval reference is required'];
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }

            $patch = [
                'academicstatus' => 'active',
                'admissionstatus' => 'active',
                'status' => 'active',
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
            ];
            foreach (['schoolid', 'departmentid', 'programmeid', 'curriculumid',
                'curriculumyearid', 'curriculumsemesterid'] as $optional) {
                if (array_key_exists($optional, $input) && (int)$input[$optional] > 0) {
                    $patch[$optional] = (int)$input[$optional];
                }
            }

            return [
                'ok' => true,
                'profilepatch' => $patch,
                'historyextra' => [
                    'effectivedate' => $effectivedate,
                    'reason' => $reason,
                    'notes' => (string)($input['notes'] ?? ''),
                ],
            ];
        });
    }

    /**
     * Update current academic placement while preserving history.
     *
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,errors?:list}
     */
    public static function update_placement(array $input, int $actorid = 0): array {
        return self::run_lifecycle('placement_update', $input, $actorid, function (array $profile, array $input) {
            $errors = [];
            if ((string)$profile['academicstatus'] === 'completed') {
                $errors[] = ['field' => 'academicstatus', 'message' => 'Cannot update placement for a graduated student'];
            }
            $effectivedate = (int)($input['effectivedate'] ?? time());
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }

            $patch = [];
            foreach ([
                'schoolid', 'departmentid', 'programmeid', 'curriculumid',
                'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid',
            ] as $field) {
                if (array_key_exists($field, $input)) {
                    $patch[$field] = (int)$input[$field];
                }
            }
            if (array_key_exists('academicstatus', $input) && $input['academicstatus'] !== '') {
                $patch['academicstatus'] = strtolower(trim((string)$input['academicstatus']));
            }
            if (!$patch) {
                return ['ok' => false, 'errors' => [['field' => '', 'message' => 'No placement changes provided']]];
            }

            return [
                'ok' => true,
                'profilepatch' => $patch,
                'historyextra' => [
                    'effectivedate' => $effectivedate,
                    'reason' => (string)($input['reason'] ?? 'Academic placement update'),
                    'notes' => (string)($input['notes'] ?? ''),
                ],
            ];
        });
    }

    /**
     * Shared transactional lifecycle engine.
     *
     * @param callable(array,array):array $builder
     * @return array{ok:bool,profile?:array,history?:array,enrolment?:array,moodleuserid?:int,errors?:list}
     */
    private static function run_lifecycle(string $actiontype, array $input, int $actorid, callable $builder): array {
        global $DB;

        $userid = (int)($input['userid'] ?? 0);
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Student academic profile not found']]];
        }

        $built = $builder($profile, $input);
        if (empty($built['ok'])) {
            return ['ok' => false, 'errors' => $built['errors'] ?? [['field' => '', 'message' => 'Lifecycle validation failed']]];
        }

        $policy = strtolower(trim((string)($input['enrolmentpolicy'] ?? 'preserve')));
        if (!in_array($policy, academic_constants::ENROLMENT_POLICIES, true)) {
            return ['ok' => false, 'errors' => [['field' => 'enrolmentpolicy', 'message' => 'Invalid enrolment policy']]];
        }

        $courseidstoenrol = self::int_list($input['courseidstoenrol'] ?? []);
        $courseidstounenrol = self::int_list($input['courseidstounenrol'] ?? []);

        $previous = student_academic_history_helper::snapshot_from_profile($profile);
        $transaction = $DB->start_delegated_transaction();
        try {
            $update = student_profile_helper::update((int)$profile['id'], $built['profilepatch'], $actorid);
            if (!$update['ok']) {
                throw new \Exception($update['errors'][0]['message'] ?? 'Profile update failed');
            }
            $updated = $update['record'];

            $historyfields = array_merge(
                [
                    'userid' => $userid,
                    'actiontype' => $actiontype,
                    'approvalstatus' => (string)($input['approvalstatus'] ?? 'recorded'),
                    'approvedby' => (int)($input['approvedby'] ?? $actorid),
                    'enrolmentpolicy' => $policy,
                    'enrolmentresult' => '',
                ],
                $previous,
                student_academic_history_helper::snapshot_new_from_profile($updated),
                $built['historyextra'] ?? []
            );
            $history = student_academic_history_helper::create($historyfields, $actorid);
            if (!$history['ok']) {
                throw new \Exception($history['errors'][0]['message'] ?? 'History insert failed');
            }

            // Enrolment transition after academic writes so a failed transition rolls both back.
            $enrolment = enrolment_transition_helper::apply(
                $userid,
                $policy,
                $courseidstoenrol,
                $courseidstounenrol
            );
            if (!$enrolment['ok']) {
                throw new \Exception($enrolment['errors'][0]['message'] ?? 'Enrolment transition failed');
            }

            $historyrow = $DB->get_record(student_academic_history_helper::TABLE, ['id' => (int)$history['record']['id']]);
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
            'moodleuserid' => $userid,
            'profile' => $updated,
            'history' => $history['record'],
            'previous' => $previous,
            'enrolment' => $enrolment['result'],
        ];
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private static function int_list($value): array {
        if (is_string($value) && $value !== '') {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    public static function lifecycle_result_structure_keys(): array {
        return [
            'moodleuserid' => new \core_external\external_value(PARAM_INT, 'Same Moodle user id before and after'),
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
        ];
    }
}
