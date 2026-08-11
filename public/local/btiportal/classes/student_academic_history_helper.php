<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Historical student academic lifecycle records.
 *
 * Identity remains on the Moodle user; this table stores placement transitions only.
 */
class student_academic_history_helper {
    public const TABLE = 'local_btiportal_student_acad_hist';

    /**
     * @param array $fields
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create(array $fields, int $createdby = 0): array {
        global $DB;

        $errors = self::validate($fields);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $record = (object)[
            'userid' => (int)$fields['userid'],
            'actiontype' => (string)$fields['actiontype'],
            'prevschoolid' => (int)($fields['prevschoolid'] ?? 0),
            'prevdepartmentid' => (int)($fields['prevdepartmentid'] ?? 0),
            'prevprogrammeid' => (int)($fields['prevprogrammeid'] ?? 0),
            'prevcurriculumid' => (int)($fields['prevcurriculumid'] ?? 0),
            'prevcurriculumyearid' => (int)($fields['prevcurriculumyearid'] ?? 0),
            'prevcurriculumsemesterid' => (int)($fields['prevcurriculumsemesterid'] ?? 0),
            'prevacademicyearid' => (int)($fields['prevacademicyearid'] ?? 0),
            'prevsemesterid' => (int)($fields['prevsemesterid'] ?? 0),
            'prevacademicstatus' => (string)($fields['prevacademicstatus'] ?? ''),
            'newschoolid' => (int)($fields['newschoolid'] ?? 0),
            'newdepartmentid' => (int)($fields['newdepartmentid'] ?? 0),
            'newprogrammeid' => (int)($fields['newprogrammeid'] ?? 0),
            'newcurriculumid' => (int)($fields['newcurriculumid'] ?? 0),
            'newcurriculumyearid' => (int)($fields['newcurriculumyearid'] ?? 0),
            'newcurriculumsemesterid' => (int)($fields['newcurriculumsemesterid'] ?? 0),
            'newacademicyearid' => (int)($fields['newacademicyearid'] ?? 0),
            'newsemesterid' => (int)($fields['newsemesterid'] ?? 0),
            'newacademicstatus' => (string)($fields['newacademicstatus'] ?? ''),
            'effectivedate' => (int)($fields['effectivedate'] ?? $now),
            'expectedreturndate' => (int)($fields['expectedreturndate'] ?? 0),
            'reason' => (string)($fields['reason'] ?? ''),
            'notes' => (string)($fields['notes'] ?? ''),
            'approvalstatus' => (string)($fields['approvalstatus'] ?? 'recorded'),
            'approvedby' => (int)($fields['approvedby'] ?? 0),
            'enrolmentpolicy' => (string)($fields['enrolmentpolicy'] ?? 'preserve'),
            'enrolmentresult' => (string)($fields['enrolmentresult'] ?? ''),
            'createdby' => $createdby,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $id = $DB->insert_record(self::TABLE, $record);
        return ['ok' => true, 'record' => self::get($id)];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list_for_user(int $userid, ?string $actiontype = null): array {
        global $DB;
        $params = ['userid' => $userid];
        $where = 'userid = :userid';
        if ($actiontype !== null && $actiontype !== '') {
            $where .= ' AND actiontype = :actiontype';
            $params['actiontype'] = $actiontype;
        }
        $rows = $DB->get_records_select(
            self::TABLE,
            $where,
            $params,
            'effectivedate DESC, id DESC',
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
     * Count lifecycle events of a given type since a unix timestamp (inclusive).
     */
    public static function count_since(string $actiontype, int $since): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(self::TABLE)) {
            return 0;
        }
        return (int)$DB->count_records_select(
            self::TABLE,
            'actiontype = ? AND timecreated >= ?',
            [$actiontype, $since]
        );
    }

    /**
     * Snapshot previous placement fields from a student profile row.
     *
     * @return array<string,int|string>
     */
    public static function snapshot_from_profile(array $profile): array {
        return [
            'prevschoolid' => (int)($profile['schoolid'] ?? 0),
            'prevdepartmentid' => (int)($profile['departmentid'] ?? 0),
            'prevprogrammeid' => (int)($profile['programmeid'] ?? 0),
            'prevcurriculumid' => (int)($profile['curriculumid'] ?? 0),
            'prevcurriculumyearid' => (int)($profile['curriculumyearid'] ?? 0),
            'prevcurriculumsemesterid' => (int)($profile['curriculumsemesterid'] ?? 0),
            'prevacademicyearid' => (int)($profile['academicyearid'] ?? 0),
            'prevsemesterid' => (int)($profile['semesterid'] ?? 0),
            'prevacademicstatus' => (string)($profile['academicstatus'] ?? ''),
        ];
    }

    /**
     * @return array<string,int|string>
     */
    public static function snapshot_new_from_profile(array $profile): array {
        return [
            'newschoolid' => (int)($profile['schoolid'] ?? 0),
            'newdepartmentid' => (int)($profile['departmentid'] ?? 0),
            'newprogrammeid' => (int)($profile['programmeid'] ?? 0),
            'newcurriculumid' => (int)($profile['curriculumid'] ?? 0),
            'newcurriculumyearid' => (int)($profile['curriculumyearid'] ?? 0),
            'newcurriculumsemesterid' => (int)($profile['curriculumsemesterid'] ?? 0),
            'newacademicyearid' => (int)($profile['academicyearid'] ?? 0),
            'newsemesterid' => (int)($profile['semesterid'] ?? 0),
            'newacademicstatus' => (string)($profile['academicstatus'] ?? ''),
        ];
    }

    /**
     * @return list<array{field:string,message:string}>
     */
    private static function validate(array $fields): array {
        global $DB;
        $errors = [];
        $userid = (int)($fields['userid'] ?? 0);
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            $errors[] = ['field' => 'userid', 'message' => 'Moodle user not found'];
        }
        $action = (string)($fields['actiontype'] ?? '');
        if (!in_array($action, academic_constants::LIFECYCLE_ACTION_TYPES, true)) {
            $errors[] = ['field' => 'actiontype', 'message' => 'Invalid lifecycle action type'];
        }
        $approval = (string)($fields['approvalstatus'] ?? 'recorded');
        if (!in_array($approval, academic_constants::LIFECYCLE_APPROVAL_STATUSES, true)) {
            $errors[] = ['field' => 'approvalstatus', 'message' => 'Invalid approval status'];
        }
        $policy = (string)($fields['enrolmentpolicy'] ?? 'preserve');
        if (!in_array($policy, academic_constants::ENROLMENT_POLICIES, true)) {
            $errors[] = ['field' => 'enrolmentpolicy', 'message' => 'Invalid enrolment policy'];
        }
        return $errors;
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'actiontype' => (string)$row->actiontype,
            'prevschoolid' => (int)$row->prevschoolid,
            'prevdepartmentid' => (int)$row->prevdepartmentid,
            'prevprogrammeid' => (int)$row->prevprogrammeid,
            'prevcurriculumid' => (int)$row->prevcurriculumid,
            'prevcurriculumyearid' => (int)$row->prevcurriculumyearid,
            'prevcurriculumsemesterid' => (int)$row->prevcurriculumsemesterid,
            'prevacademicyearid' => (int)$row->prevacademicyearid,
            'prevsemesterid' => (int)$row->prevsemesterid,
            'prevacademicstatus' => (string)$row->prevacademicstatus,
            'newschoolid' => (int)$row->newschoolid,
            'newdepartmentid' => (int)$row->newdepartmentid,
            'newprogrammeid' => (int)$row->newprogrammeid,
            'newcurriculumid' => (int)$row->newcurriculumid,
            'newcurriculumyearid' => (int)$row->newcurriculumyearid,
            'newcurriculumsemesterid' => (int)$row->newcurriculumsemesterid,
            'newacademicyearid' => (int)$row->newacademicyearid,
            'newsemesterid' => (int)$row->newsemesterid,
            'newacademicstatus' => (string)$row->newacademicstatus,
            'effectivedate' => (int)$row->effectivedate,
            'expectedreturndate' => (int)$row->expectedreturndate,
            'reason' => (string)$row->reason,
            'notes' => (string)$row->notes,
            'approvalstatus' => (string)$row->approvalstatus,
            'approvedby' => (int)$row->approvedby,
            'enrolmentpolicy' => (string)$row->enrolmentpolicy,
            'enrolmentresult' => (string)$row->enrolmentresult,
            'createdby' => (int)$row->createdby,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'userid' => new \core_external\external_value(PARAM_INT, 'Moodle user id'),
            'actiontype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'actiontype'),
            'prevschoolid' => new \core_external\external_value(PARAM_INT, 'prevschoolid'),
            'prevdepartmentid' => new \core_external\external_value(PARAM_INT, 'prevdepartmentid'),
            'prevprogrammeid' => new \core_external\external_value(PARAM_INT, 'prevprogrammeid'),
            'prevcurriculumid' => new \core_external\external_value(PARAM_INT, 'prevcurriculumid'),
            'prevcurriculumyearid' => new \core_external\external_value(PARAM_INT, 'prevcurriculumyearid'),
            'prevcurriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'prevcurriculumsemesterid'),
            'prevacademicyearid' => new \core_external\external_value(PARAM_INT, 'prevacademicyearid'),
            'prevsemesterid' => new \core_external\external_value(PARAM_INT, 'prevsemesterid'),
            'prevacademicstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'prevacademicstatus'),
            'newschoolid' => new \core_external\external_value(PARAM_INT, 'newschoolid'),
            'newdepartmentid' => new \core_external\external_value(PARAM_INT, 'newdepartmentid'),
            'newprogrammeid' => new \core_external\external_value(PARAM_INT, 'newprogrammeid'),
            'newcurriculumid' => new \core_external\external_value(PARAM_INT, 'newcurriculumid'),
            'newcurriculumyearid' => new \core_external\external_value(PARAM_INT, 'newcurriculumyearid'),
            'newcurriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'newcurriculumsemesterid'),
            'newacademicyearid' => new \core_external\external_value(PARAM_INT, 'newacademicyearid'),
            'newsemesterid' => new \core_external\external_value(PARAM_INT, 'newsemesterid'),
            'newacademicstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'newacademicstatus'),
            'effectivedate' => new \core_external\external_value(PARAM_INT, 'effectivedate'),
            'expectedreturndate' => new \core_external\external_value(PARAM_INT, 'expectedreturndate'),
            'reason' => new \core_external\external_value(PARAM_TEXT, 'reason'),
            'notes' => new \core_external\external_value(PARAM_TEXT, 'notes'),
            'approvalstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'approvalstatus'),
            'approvedby' => new \core_external\external_value(PARAM_INT, 'approvedby'),
            'enrolmentpolicy' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'enrolmentpolicy'),
            'enrolmentresult' => new \core_external\external_value(PARAM_TEXT, 'enrolmentresult'),
            'createdby' => new \core_external\external_value(PARAM_INT, 'createdby'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
        ];
    }
}
