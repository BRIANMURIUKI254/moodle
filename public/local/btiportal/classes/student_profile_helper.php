<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Academic placement for an existing Moodle user.
 *
 * This is not an identity store: the Moodle user account is created elsewhere
 * (the portal) and this table only records where that user sits academically.
 */
class student_profile_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function create(array $input, int $userid = 0): array {
        global $DB;

        $fields = self::read_input($input, null);
        $errors = self::validate($fields, null);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $record = (object)($fields + [
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);
        $id = $DB->insert_record('local_btiportal_student_profile', $record);

        return ['ok' => true, 'record' => self::get($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input, int $userid = 0): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_student_profile', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Student profile not found']]];
        }

        $fields = self::read_input($input, $row);
        $errors = self::validate($fields, $id);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        foreach ($fields as $key => $value) {
            $row->{$key} = $value;
        }
        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_student_profile', $row);

        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Create when absent, update when present. Keyed on the Moodle user id.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function upsert_for_user(int $targetuserid, array $input, int $userid = 0): array {
        global $DB;
        $existing = $DB->get_record('local_btiportal_student_profile', ['userid' => $targetuserid]);
        $input['userid'] = $targetuserid;
        if ($existing) {
            return self::update((int)$existing->id, $input, $userid);
        }
        return self::create($input, $userid);
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_student_profile', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    public static function get_by_userid(int $targetuserid): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_student_profile', ['userid' => $targetuserid]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list_by_programme(int $programmeid, ?string $status = null, ?int $curriculumid = null): array {
        global $DB;
        $params = ['programmeid' => $programmeid];
        $where = 'programmeid = :programmeid';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        if ($curriculumid !== null && $curriculumid > 0) {
            $where .= ' AND curriculumid = :curriculumid';
            $params['curriculumid'] = $curriculumid;
        }
        $rows = $DB->get_records_select(
            'local_btiportal_student_profile',
            $where,
            $params,
            'curriculumyearid ASC, userid ASC',
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
     * Resolves the effective field set, falling back to the stored row on update.
     */
    private static function read_input(array $input, ?\stdClass $row): array {
        $pick = function (string $key, $default) use ($input, $row) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }
            if ($row !== null && isset($row->{$key})) {
                return $row->{$key};
            }
            return $default;
        };

        return [
            'userid' => (int)$pick('userid', 0),
            'schoolid' => (int)$pick('schoolid', 0),
            'departmentid' => (int)$pick('departmentid', 0),
            'programmeid' => (int)$pick('programmeid', 0),
            'curriculumid' => (int)$pick('curriculumid', 0),
            'curriculumyearid' => (int)$pick('curriculumyearid', 0),
            'curriculumsemesterid' => (int)$pick('curriculumsemesterid', 0),
            'academicyearid' => (int)$pick('academicyearid', 0),
            'semesterid' => (int)$pick('semesterid', 0),
            'admissiondate' => (int)$pick('admissiondate', 0),
            'admissionstatus' => strtolower(trim((string)$pick('admissionstatus', 'active'))),
            'academicstatus' => strtolower(trim((string)$pick('academicstatus', 'active'))),
            'expectedcompletion' => (int)$pick('expectedcompletion', 0),
            'status' => strtolower(trim((string)$pick('status', 'active'))),
            'entrytype' => strtolower(trim((string)$pick('entrytype', ''))),
            'studymode' => strtolower(trim((string)$pick('studymode', ''))),
            'deliverymode' => strtolower(trim((string)$pick('deliverymode', ''))),
            'campus' => trim((string)$pick('campus', '')),
            'intake' => trim((string)$pick('intake', '')),
            'scholarship' => trim((string)$pick('scholarship', '')),
            'sponsor' => trim((string)$pick('sponsor', '')),
        ];
    }

    /**
     * @return list<array{field:string,message:string}>
     */
    private static function validate(array $f, ?int $excludeid): array {
        global $DB;
        $errors = [];

        if ($f['userid'] <= 0 || !$DB->record_exists('user', ['id' => $f['userid'], 'deleted' => 0])) {
            $errors[] = ['field' => 'userid', 'message' => 'Moodle user not found'];
        } else {
            $dup = $DB->get_record('local_btiportal_student_profile', ['userid' => $f['userid']]);
            if ($dup && ($excludeid === null || (int)$dup->id !== $excludeid)) {
                $errors[] = ['field' => 'userid', 'message' => 'This user already has a student academic profile'];
            }
        }

        $programme = $f['programmeid'] > 0
            ? $DB->get_record('local_btiportal_programme', ['id' => $f['programmeid']])
            : false;
        if (!$programme) {
            $errors[] = ['field' => 'programmeid', 'message' => 'Programme not found'];
        }

        $curriculum = $f['curriculumid'] > 0
            ? $DB->get_record('local_btiportal_curriculum', ['id' => $f['curriculumid']])
            : false;
        if (!$curriculum) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Curriculum not found'];
        } else if ($programme && (int)$curriculum->programmeid !== (int)$programme->id) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Curriculum does not belong to this programme'];
        }

        if ($f['schoolid'] > 0 && !$DB->record_exists('local_btiportal_school', ['id' => $f['schoolid']])) {
            $errors[] = ['field' => 'schoolid', 'message' => 'School not found'];
        }
        if ($f['departmentid'] > 0 && !department_helper::exists($f['departmentid'])) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department not found'];
        }

        if ($f['curriculumyearid'] > 0) {
            $year = $DB->get_record('local_btiportal_curriculum_year', ['id' => $f['curriculumyearid']]);
            if (!$year) {
                $errors[] = ['field' => 'curriculumyearid', 'message' => 'Year of study not found'];
            } else if ($curriculum && (int)$year->curriculumid !== (int)$curriculum->id) {
                $errors[] = ['field' => 'curriculumyearid', 'message' => 'Year of study belongs to another curriculum'];
            }
        }
        if ($f['curriculumsemesterid'] > 0) {
            $sem = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $f['curriculumsemesterid']]);
            if (!$sem) {
                $errors[] = ['field' => 'curriculumsemesterid', 'message' => 'Curriculum semester not found'];
            } else if ($f['curriculumyearid'] > 0 && (int)$sem->curriculumyearid !== $f['curriculumyearid']) {
                $errors[] = [
                    'field' => 'curriculumsemesterid',
                    'message' => 'Curriculum semester belongs to another year of study',
                ];
            }
        }

        if ($f['academicyearid'] > 0 && !$DB->record_exists('local_btiportal_academic_year', ['id' => $f['academicyearid']])) {
            $errors[] = ['field' => 'academicyearid', 'message' => 'Academic year not found'];
        }
        if ($f['semesterid'] > 0) {
            $institutional = $DB->get_record('local_btiportal_semester', ['id' => $f['semesterid']]);
            if (!$institutional) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Semester not found'];
            } else if ($f['academicyearid'] > 0 && (int)$institutional->academicyearid !== $f['academicyearid']) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Semester belongs to another academic year'];
            }
        }

        if (!in_array($f['admissionstatus'], academic_constants::ADMISSION_STATUSES, true)) {
            $errors[] = ['field' => 'admissionstatus', 'message' => 'Invalid admission status'];
        }
        if (!in_array($f['academicstatus'], academic_constants::ACADEMIC_STATUSES, true)) {
            $errors[] = ['field' => 'academicstatus', 'message' => 'Invalid academic status'];
        }
        if (!in_array($f['status'], academic_constants::STUDENT_PROFILE_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
        }

        return $errors;
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'schoolid' => (int)$row->schoolid,
            'departmentid' => (int)$row->departmentid,
            'programmeid' => (int)$row->programmeid,
            'curriculumid' => (int)$row->curriculumid,
            'curriculumyearid' => (int)$row->curriculumyearid,
            'curriculumsemesterid' => (int)$row->curriculumsemesterid,
            'academicyearid' => (int)$row->academicyearid,
            'semesterid' => (int)$row->semesterid,
            'admissiondate' => (int)$row->admissiondate,
            'admissionstatus' => (string)$row->admissionstatus,
            'academicstatus' => (string)$row->academicstatus,
            'expectedcompletion' => (int)$row->expectedcompletion,
            'status' => (string)$row->status,
            'entrytype' => (string)($row->entrytype ?? ''),
            'studymode' => (string)($row->studymode ?? ''),
            'deliverymode' => (string)($row->deliverymode ?? ''),
            'campus' => (string)($row->campus ?? ''),
            'intake' => (string)($row->intake ?? ''),
            'scholarship' => (string)($row->scholarship ?? ''),
            'sponsor' => (string)($row->sponsor ?? ''),
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)($row->usermodified ?? 0),
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'userid' => new \core_external\external_value(PARAM_INT, 'Moodle user id'),
            'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
            'curriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'curriculumsemesterid'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'admissiondate' => new \core_external\external_value(PARAM_INT, 'admissiondate'),
            'admissionstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'admissionstatus'),
            'academicstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'academicstatus'),
            'expectedcompletion' => new \core_external\external_value(PARAM_INT, 'expectedcompletion'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'entrytype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'entrytype', VALUE_OPTIONAL),
            'studymode' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'studymode', VALUE_OPTIONAL),
            'deliverymode' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'deliverymode', VALUE_OPTIONAL),
            'campus' => new \core_external\external_value(PARAM_TEXT, 'campus', VALUE_OPTIONAL),
            'intake' => new \core_external\external_value(PARAM_TEXT, 'intake', VALUE_OPTIONAL),
            'scholarship' => new \core_external\external_value(PARAM_TEXT, 'scholarship', VALUE_OPTIONAL),
            'sponsor' => new \core_external\external_value(PARAM_TEXT, 'sponsor', VALUE_OPTIONAL),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }
}
