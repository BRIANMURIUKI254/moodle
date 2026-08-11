<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Multi-role Course Unit teachers.
 *
 * Academic assignments live in local_btiportal_course_unit_teacher. When a unit
 * is mapped to a Moodle topic section, active assignments are also synced into
 * local_btiportal_unit so the Lecturer portal (get_my_teaching_sections,
 * gradebook, content) sees the same teaching load. Moodle enrolments are still
 * managed separately.
 */
class course_unit_teacher_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function assign(array $input, int $assignedby): array {
        global $DB;

        $courseunitid = (int)($input['courseunitid'] ?? 0);
        $userid = (int)($input['userid'] ?? 0);
        $teachingrole = strtolower(trim((string)($input['teachingrole'] ?? '')));
        $isprimary = !empty($input['isprimary']) ? 1 : 0;
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $academicyearid = (int)($input['academicyearid'] ?? 0);
        $semesterid = (int)($input['semesterid'] ?? 0);

        $errors = [];
        if ($courseunitid <= 0 || !$DB->record_exists('local_btiportal_course_unit', ['id' => $courseunitid])) {
            $errors[] = ['field' => 'courseunitid', 'message' => 'Course unit not found'];
        }
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            $errors[] = ['field' => 'userid', 'message' => 'Moodle user not found'];
        }
        if (!in_array($teachingrole, academic_constants::TEACHING_ROLES, true)) {
            $errors[] = ['field' => 'teachingrole', 'message' => 'Invalid teaching role'];
        }
        if (!in_array($status, academic_constants::TEACHER_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid teacher status'];
        }
        if ($academicyearid > 0 && !$DB->record_exists('local_btiportal_academic_year', ['id' => $academicyearid])) {
            $errors[] = ['field' => 'academicyearid', 'message' => 'Academic year not found'];
        }
        if ($semesterid > 0) {
            $sem = $DB->get_record('local_btiportal_semester', ['id' => $semesterid]);
            if (!$sem) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Institutional semester not found'];
            } else if ($academicyearid > 0 && (int)$sem->academicyearid !== $academicyearid) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Semester does not belong to the selected Academic Year'];
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        if ($status === 'active') {
            $dup = $DB->get_record('local_btiportal_course_unit_teacher', [
                'courseunitid' => $courseunitid,
                'userid' => $userid,
                'teachingrole' => $teachingrole,
                'status' => 'active',
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
            ]);
            if ($dup) {
                return ['ok' => false, 'errors' => [[
                    'field' => 'userid',
                    'message' => 'User already has this active teaching role on the course unit for this period',
                ]]];
            }
            $roleerr = self::check_role_uniqueness($courseunitid, $teachingrole, $isprimary, null, $academicyearid, $semesterid);
            if ($roleerr) {
                return ['ok' => false, 'errors' => $roleerr];
            }
        }

        if ($teachingrole === academic_constants::ROLE_PRIMARY_LECTURER && $status === 'active') {
            $isprimary = 1;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($isprimary && $status === 'active') {
                self::clear_primary_flags($courseunitid, null, $academicyearid, $semesterid);
            }
            if ($teachingrole === academic_constants::ROLE_PRIMARY_LECTURER && $status === 'active') {
                self::demote_other_primary_lecturers($courseunitid, null, $academicyearid, $semesterid);
            }
            if ($teachingrole === academic_constants::ROLE_COURSE_COORDINATOR && $status === 'active') {
                self::demote_other_coordinators($courseunitid, null, $academicyearid, $semesterid);
            }

            $id = $DB->insert_record('local_btiportal_course_unit_teacher', (object)[
                'courseunitid' => $courseunitid,
                'userid' => $userid,
                'teachingrole' => $teachingrole,
                'isprimary' => $isprimary,
                'status' => $status,
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
                'assignedby' => $assignedby,
                'timeassigned' => time(),
                'timeremoved' => 0,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        self::sync_legacy_unit_teacher($courseunitid, $userid, $status);

        return ['ok' => true, 'record' => self::get($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit_teacher', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Teacher assignment not found']]];
        }
        if ((string)$row->status === 'removed') {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Cannot update a removed assignment']]];
        }

        $teachingrole = array_key_exists('teachingrole', $input)
            ? strtolower(trim((string)$input['teachingrole'])) : (string)$row->teachingrole;
        $isprimary = array_key_exists('isprimary', $input)
            ? (!empty($input['isprimary']) ? 1 : 0) : (int)$row->isprimary;
        $status = array_key_exists('status', $input)
            ? strtolower(trim((string)$input['status'])) : (string)$row->status;
        $academicyearid = array_key_exists('academicyearid', $input)
            ? (int)$input['academicyearid'] : (int)($row->academicyearid ?? 0);
        $semesterid = array_key_exists('semesterid', $input)
            ? (int)$input['semesterid'] : (int)($row->semesterid ?? 0);

        $errors = [];
        if (!in_array($teachingrole, academic_constants::TEACHING_ROLES, true)) {
            $errors[] = ['field' => 'teachingrole', 'message' => 'Invalid teaching role'];
        }
        if (!in_array($status, academic_constants::TEACHER_STATUSES, true) || $status === 'removed') {
            if ($status === 'removed') {
                $errors[] = ['field' => 'status', 'message' => 'Use remove endpoint to remove an assignment'];
            } else {
                $errors[] = ['field' => 'status', 'message' => 'Invalid teacher status'];
            }
        }
        if ($academicyearid > 0 && !$DB->record_exists('local_btiportal_academic_year', ['id' => $academicyearid])) {
            $errors[] = ['field' => 'academicyearid', 'message' => 'Academic year not found'];
        }
        if ($semesterid > 0) {
            $sem = $DB->get_record('local_btiportal_semester', ['id' => $semesterid]);
            if (!$sem) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Institutional semester not found'];
            } else if ($academicyearid > 0 && (int)$sem->academicyearid !== $academicyearid) {
                $errors[] = ['field' => 'semesterid', 'message' => 'Semester does not belong to the selected Academic Year'];
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        if ($status === 'active') {
            $dup = $DB->get_record('local_btiportal_course_unit_teacher', [
                'courseunitid' => (int)$row->courseunitid,
                'userid' => (int)$row->userid,
                'teachingrole' => $teachingrole,
                'status' => 'active',
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
            ]);
            if ($dup && (int)$dup->id !== $id) {
                return ['ok' => false, 'errors' => [[
                    'field' => 'teachingrole',
                    'message' => 'User already has this active teaching role on the course unit for this period',
                ]]];
            }
        }

        if ($teachingrole === academic_constants::ROLE_PRIMARY_LECTURER && $status === 'active') {
            $isprimary = 1;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($isprimary && $status === 'active') {
                self::clear_primary_flags((int)$row->courseunitid, $id, $academicyearid, $semesterid);
            }
            if ($teachingrole === academic_constants::ROLE_PRIMARY_LECTURER && $status === 'active') {
                self::demote_other_primary_lecturers((int)$row->courseunitid, $id, $academicyearid, $semesterid);
            }
            if ($teachingrole === academic_constants::ROLE_COURSE_COORDINATOR && $status === 'active') {
                self::demote_other_coordinators((int)$row->courseunitid, $id, $academicyearid, $semesterid);
            }
            $row->teachingrole = $teachingrole;
            $row->isprimary = $isprimary;
            $row->status = $status;
            $row->academicyearid = $academicyearid;
            $row->semesterid = $semesterid;
            $DB->update_record('local_btiportal_course_unit_teacher', $row);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        self::sync_legacy_unit_teacher((int)$row->courseunitid, (int)$row->userid, $status);

        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Soft-remove. Does not delete Moodle user or unenrol.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function remove(int $id): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit_teacher', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Teacher assignment not found']]];
        }
        $row->status = 'removed';
        $row->isprimary = 0;
        $row->timeremoved = time();
        $DB->update_record('local_btiportal_course_unit_teacher', $row);
        self::sync_legacy_unit_teacher((int)$row->courseunitid, (int)$row->userid, 'removed');
        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Keep local_btiportal_unit in sync when a Course Unit has a Moodle topic mapping.
     * General (section 0) is never synced.
     */
    private static function sync_legacy_unit_teacher(int $courseunitid, int $userid, string $status): void {
        global $DB;

        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit || (int)$unit->courseid <= 1 || (int)$unit->sectionid <= 0) {
            return;
        }
        $section = $DB->get_record('course_sections', [
            'id' => (int)$unit->sectionid,
            'course' => (int)$unit->courseid,
        ]);
        if (!$section || (int)$section->section <= 0) {
            return;
        }

        $courseid = (int)$unit->courseid;
        $sectionid = (int)$unit->sectionid;

        if ($status === 'active') {
            unit_helper::ensure_unit_teacher($courseid, $sectionid, $userid);
            return;
        }

        // On remove/inactive: keep legacy row if another active academic teacher remains.
        $other = $DB->get_record_sql(
            "SELECT cut.userid
               FROM {local_btiportal_course_unit_teacher} cut
              WHERE cut.courseunitid = ?
                AND cut.status = 'active'
                AND cut.userid <> ?
           ORDER BY cut.isprimary DESC, cut.id ASC",
            [$courseunitid, $userid],
            IGNORE_MULTIPLE
        );
        if ($other) {
            unit_helper::ensure_unit_teacher($courseid, $sectionid, (int)$other->userid);
            return;
        }

        $legacy = $DB->get_record('local_btiportal_unit', [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ]);
        if ($legacy && (int)$legacy->teacheruserid === $userid) {
            unit_helper::remove_unit_teacher($courseid, $sectionid);
        }
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function set_primary(int $courseunitid, int $userid): array {
        global $DB;

        if (!$DB->record_exists('local_btiportal_course_unit', ['id' => $courseunitid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit not found']]];
        }

        $row = $DB->get_record('local_btiportal_course_unit_teacher', [
            'courseunitid' => $courseunitid,
            'userid' => $userid,
            'teachingrole' => academic_constants::ROLE_PRIMARY_LECTURER,
            'status' => 'active',
        ]);
        if (!$row) {
            // Allow promoting an active co-lecturer? Brief says set primary — require primary_lecturer role row.
            $any = $DB->get_record_select(
                'local_btiportal_course_unit_teacher',
                'courseunitid = ? AND userid = ? AND status = ?',
                [$courseunitid, $userid, 'active'],
                '*',
                IGNORE_MULTIPLE
            );
            if (!$any) {
                return ['ok' => false, 'errors' => [[
                    'field' => 'userid',
                    'message' => 'No active teacher assignment found for this user on the course unit',
                ]]];
            }
            $row = $any;
            $row->teachingrole = academic_constants::ROLE_PRIMARY_LECTURER;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            self::clear_primary_flags($courseunitid, (int)$row->id);
            self::demote_other_primary_lecturers($courseunitid, (int)$row->id);
            $row->teachingrole = academic_constants::ROLE_PRIMARY_LECTURER;
            $row->isprimary = 1;
            $row->status = 'active';
            $DB->update_record('local_btiportal_course_unit_teacher', $row);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        self::sync_legacy_unit_teacher($courseunitid, $userid, 'active');

        return ['ok' => true, 'record' => self::format($row)];
    }

    /** @return list<array> */
    public static function list_for_unit(int $courseunitid, bool $includelegacy = true): array {
        global $DB;
        $rows = $DB->get_records(
            'local_btiportal_course_unit_teacher',
            ['courseunitid' => $courseunitid],
            'status ASC, teachingrole ASC, id ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            if ((string)$row->status === 'removed') {
                continue;
            }
            $out[] = self::format($row);
        }
        if ($includelegacy) {
            $legacy = academic_teacher_compat_helper::legacy_for_course_unit($courseunitid);
            if ($legacy) {
                $out[] = $legacy;
            }
        }
        return $out;
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit_teacher', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /** @return list<array{field:string,message:string}> */
    private static function check_role_uniqueness(
        int $courseunitid,
        string $teachingrole,
        int $isprimary,
        ?int $excludeid,
        int $academicyearid = 0,
        int $semesterid = 0
    ): array {
        // Uniqueness is enforced by demote_other_* inside the transaction.
        return [];
    }

    private static function clear_primary_flags(
        int $courseunitid,
        ?int $excludeid,
        int $academicyearid = 0,
        int $semesterid = 0
    ): void {
        global $DB;
        $sql = 'courseunitid = ? AND isprimary = 1 AND academicyearid = ? AND semesterid = ?';
        $params = [$courseunitid, $academicyearid, $semesterid];
        if ($excludeid) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeid;
        }
        $DB->set_field_select('local_btiportal_course_unit_teacher', 'isprimary', 0, $sql, $params);
    }

    private static function demote_other_primary_lecturers(
        int $courseunitid,
        ?int $excludeid,
        int $academicyearid = 0,
        int $semesterid = 0
    ): void {
        global $DB;
        $sql = "courseunitid = ? AND teachingrole = ? AND status = 'active'
                AND academicyearid = ? AND semesterid = ?";
        $params = [$courseunitid, academic_constants::ROLE_PRIMARY_LECTURER, $academicyearid, $semesterid];
        if ($excludeid) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeid;
        }
        $others = $DB->get_records_select('local_btiportal_course_unit_teacher', $sql, $params);
        foreach ($others as $other) {
            $other->teachingrole = 'co_lecturer';
            $other->isprimary = 0;
            $DB->update_record('local_btiportal_course_unit_teacher', $other);
        }
    }

    private static function demote_other_coordinators(
        int $courseunitid,
        ?int $excludeid,
        int $academicyearid = 0,
        int $semesterid = 0
    ): void {
        global $DB;
        $sql = "courseunitid = ? AND teachingrole = ? AND status = 'active'
                AND academicyearid = ? AND semesterid = ?";
        $params = [$courseunitid, academic_constants::ROLE_COURSE_COORDINATOR, $academicyearid, $semesterid];
        if ($excludeid) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeid;
        }
        $others = $DB->get_records_select('local_btiportal_course_unit_teacher', $sql, $params);
        foreach ($others as $other) {
            $other->status = 'inactive';
            $DB->update_record('local_btiportal_course_unit_teacher', $other);
        }
    }

    public static function format(\stdClass $row): array {
        $yearid = (int)($row->academicyearid ?? 0);
        $semid = (int)($row->semesterid ?? 0);
        return [
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
            'islegacy' => 0,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'teachingrole' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'teachingrole'),
            'isprimary' => new \core_external\external_value(PARAM_INT, 'isprimary'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'islegacyunperioded' => new \core_external\external_value(PARAM_INT, '1 when no academic period'),
            'assignedby' => new \core_external\external_value(PARAM_INT, 'assignedby'),
            'timeassigned' => new \core_external\external_value(PARAM_INT, 'timeassigned'),
            'timeremoved' => new \core_external\external_value(PARAM_INT, 'timeremoved'),
            'islegacy' => new \core_external\external_value(PARAM_INT, '1 when from local_btiportal_unit'),
        ];
    }
}
