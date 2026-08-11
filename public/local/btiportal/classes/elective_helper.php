<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Elective groups and student elective selections for a curriculum placement.
 *
 * Does not invent elective rules: curricula without groups behave as before
 * (ungrouped electives remain manual_review until selected or left alone).
 */
class elective_helper {
    public const GROUP_TABLE = 'local_btiportal_elective_group';
    public const GROUP_UNIT_TABLE = 'local_btiportal_elective_group_unit';
    public const SELECTION_TABLE = 'local_btiportal_student_elective_sel';

    public static function tables_ready(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(self::GROUP_TABLE)
            && $dbman->table_exists(self::GROUP_UNIT_TABLE)
            && $dbman->table_exists(self::SELECTION_TABLE);
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create_group(array $input, int $actorid = 0): array {
        global $DB;
        if (!self::tables_ready()) {
            return ['ok' => false, 'errors' => [['field' => 'schema', 'message' => 'Elective tables not installed']]];
        }

        $curriculumid = (int)($input['curriculumid'] ?? 0);
        $yearofstudy = (int)($input['yearofstudy'] ?? 0);
        $semestersequence = (int)($input['semestersequence'] ?? 0);
        $code = trim((string)($input['code'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = (string)($input['description'] ?? '');
        $minselect = (int)($input['minselect'] ?? 1);
        $maxselect = (int)($input['maxselect'] ?? 1);
        $mincredits = (float)($input['mincredits'] ?? 0);
        $maxcredits = (float)($input['maxcredits'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? 'active')));

        $errors = [];
        if ($curriculumid <= 0 || !$DB->record_exists('local_btiportal_curriculum', ['id' => $curriculumid])) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Curriculum not found'];
        }
        if ($yearofstudy <= 0) {
            $errors[] = ['field' => 'yearofstudy', 'message' => 'yearofstudy must be > 0'];
        }
        if ($semestersequence <= 0) {
            $errors[] = ['field' => 'semestersequence', 'message' => 'semestersequence must be > 0'];
        }
        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'Code is required'];
        }
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Name is required'];
        }
        if ($minselect < 0 || $maxselect < 0 || ($maxselect > 0 && $minselect > $maxselect)) {
            $errors[] = ['field' => 'minselect', 'message' => 'Invalid min/max selection bounds'];
        }
        if ($maxcredits > 0 && $mincredits > $maxcredits) {
            $errors[] = ['field' => 'mincredits', 'message' => 'mincredits cannot exceed maxcredits'];
        }
        if (!in_array($status, academic_constants::ELECTIVE_GROUP_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid elective group status'];
        }
        if ($curriculumid > 0 && $code !== '' && $DB->record_exists(self::GROUP_TABLE, [
            'curriculumid' => $curriculumid,
            'code' => $code,
            'yearofstudy' => $yearofstudy,
            'semestersequence' => $semestersequence,
        ])) {
            $errors[] = ['field' => 'code', 'message' => 'Elective group code already exists for this placement'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $id = $DB->insert_record(self::GROUP_TABLE, (object)[
            'curriculumid' => $curriculumid,
            'yearofstudy' => $yearofstudy,
            'semestersequence' => $semestersequence,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'minselect' => $minselect,
            'maxselect' => $maxselect,
            'mincredits' => $mincredits,
            'maxcredits' => $maxcredits,
            'status' => $status,
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return ['ok' => true, 'record' => self::get_group($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update_group(int $id, array $input, int $actorid = 0): array {
        global $DB;
        $row = $DB->get_record(self::GROUP_TABLE, ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Elective group not found']]];
        }

        $errors = [];
        foreach (['name', 'description', 'code'] as $text) {
            if (array_key_exists($text, $input)) {
                $val = trim((string)$input[$text]);
                if ($text !== 'description' && $val === '') {
                    $errors[] = ['field' => $text, 'message' => ucfirst($text) . ' is required'];
                } else {
                    $row->$text = $val;
                }
            }
        }
        foreach (['minselect', 'maxselect', 'yearofstudy', 'semestersequence'] as $intfield) {
            if (array_key_exists($intfield, $input)) {
                $row->$intfield = (int)$input[$intfield];
            }
        }
        foreach (['mincredits', 'maxcredits'] as $floatfield) {
            if (array_key_exists($floatfield, $input)) {
                $row->$floatfield = (float)$input[$floatfield];
            }
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::ELECTIVE_GROUP_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid status'];
            } else {
                $row->status = $status;
            }
        }
        if ((int)$row->minselect < 0 || ((int)$row->maxselect > 0 && (int)$row->minselect > (int)$row->maxselect)) {
            $errors[] = ['field' => 'minselect', 'message' => 'Invalid min/max selection bounds'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->usermodified = $actorid;
        $row->timemodified = time();
        $DB->update_record(self::GROUP_TABLE, $row);
        return ['ok' => true, 'record' => self::get_group($id)];
    }

    public static function get_group(int $id): ?array {
        global $DB;
        if (!self::tables_ready()) {
            return null;
        }
        $row = $DB->get_record(self::GROUP_TABLE, ['id' => $id]);
        return $row ? self::format_group($row, true) : null;
    }

    /**
     * @return list<array>
     */
    public static function list_groups(
        int $curriculumid,
        int $yearofstudy = 0,
        int $semestersequence = 0,
        ?string $status = 'active'
    ): array {
        global $DB;
        if (!self::tables_ready() || $curriculumid <= 0) {
            return [];
        }
        $params = ['curriculumid' => $curriculumid];
        $where = 'curriculumid = :curriculumid';
        if ($yearofstudy > 0) {
            $where .= ' AND yearofstudy = :yearofstudy';
            $params['yearofstudy'] = $yearofstudy;
        }
        if ($semestersequence > 0) {
            $where .= ' AND semestersequence = :semestersequence';
            $params['semestersequence'] = $semestersequence;
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            self::GROUP_TABLE,
            $where,
            $params,
            'yearofstudy ASC, semestersequence ASC, code ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format_group($row, true);
        }
        return $out;
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function add_unit_to_group(int $groupid, int $courseunitid, int $actorid = 0): array {
        global $DB;
        $group = $DB->get_record(self::GROUP_TABLE, ['id' => $groupid]);
        if (!$group) {
            return ['ok' => false, 'errors' => [['field' => 'electivegroupid', 'message' => 'Elective group not found']]];
        }
        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit || (string)$unit->status === 'archived') {
            return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit not found or archived']]];
        }
        if ((int)$unit->curriculumid !== (int)$group->curriculumid) {
            return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit does not belong to this curriculum']]];
        }
        if ($DB->record_exists(self::GROUP_UNIT_TABLE, ['electivegroupid' => $groupid, 'courseunitid' => $courseunitid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseunitid', 'message' => 'Course unit already in this elective group']]];
        }

        $now = time();
        $id = $DB->insert_record(self::GROUP_UNIT_TABLE, (object)[
            'electivegroupid' => $groupid,
            'courseunitid' => $courseunitid,
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return ['ok' => true, 'record' => ['id' => $id, 'electivegroupid' => $groupid, 'courseunitid' => $courseunitid]];
    }

    /**
     * @return array{ok:bool,errors?:list}
     */
    public static function remove_unit_from_group(int $groupid, int $courseunitid): array {
        global $DB;
        $DB->delete_records(self::GROUP_UNIT_TABLE, [
            'electivegroupid' => $groupid,
            'courseunitid' => $courseunitid,
        ]);
        return ['ok' => true];
    }

    /**
     * Save student elective selections for one group in a period.
     *
     * @param list<int> $courseunitids
     * @return array{ok:bool,selections?:list,errors?:list,validation?:array}
     */
    public static function save_student_selections(
        int $userid,
        int $electivegroupid,
        array $courseunitids,
        int $academicyearid,
        int $semesterid,
        int $actorid = 0,
        string $status = 'confirmed'
    ): array {
        global $DB;

        if (!self::tables_ready()) {
            return ['ok' => false, 'errors' => [['field' => 'schema', 'message' => 'Elective tables not installed']]];
        }
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }
        $group = self::get_group($electivegroupid);
        if (!$group || ($group['status'] ?? '') !== 'active') {
            return ['ok' => false, 'errors' => [['field' => 'electivegroupid', 'message' => 'Active elective group not found']]];
        }
        if (!in_array($status, academic_constants::STUDENT_ELECTIVE_STATUSES, true)) {
            return ['ok' => false, 'errors' => [['field' => 'status', 'message' => 'Invalid selection status']]];
        }

        $allowed = [];
        foreach ($group['units'] as $u) {
            $allowed[(int)$u['courseunitid']] = $u;
        }
        $courseunitids = array_values(array_unique(array_map('intval', $courseunitids)));
        $errors = [];
        foreach ($courseunitids as $cuid) {
            if (!isset($allowed[$cuid])) {
                $errors[] = ['field' => 'courseunitid', 'message' => "Course unit {$cuid} is not in this elective group"];
            } else if (($allowed[$cuid]['unitstatus'] ?? '') === 'archived') {
                $errors[] = ['field' => 'courseunitid', 'message' => "Course unit {$cuid} is archived"];
            }
        }

        $validation = self::validate_group_selection($group, $courseunitids);
        if (!$validation['ok']) {
            $errors = array_merge($errors, $validation['errors']);
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'validation' => $validation];
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        // Withdraw previous selections for this group/period.
        $existing = $DB->get_records(self::SELECTION_TABLE, [
            'userid' => $userid,
            'electivegroupid' => $electivegroupid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ]);
        foreach ($existing as $row) {
            if ((string)$row->status !== 'withdrawn') {
                $row->status = 'withdrawn';
                $row->timemodified = $now;
                $row->usermodified = $actorid;
                $DB->update_record(self::SELECTION_TABLE, $row);
            }
        }

        $saved = [];
        foreach ($courseunitids as $cuid) {
            $prev = $DB->get_record(self::SELECTION_TABLE, [
                'userid' => $userid,
                'electivegroupid' => $electivegroupid,
                'courseunitid' => $cuid,
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
            ]);
            if ($prev) {
                $prev->status = $status;
                $prev->timemodified = $now;
                $prev->usermodified = $actorid;
                $DB->update_record(self::SELECTION_TABLE, $prev);
                $saved[] = self::format_selection($prev);
            } else {
                $id = $DB->insert_record(self::SELECTION_TABLE, (object)[
                    'userid' => $userid,
                    'electivegroupid' => $electivegroupid,
                    'courseunitid' => $cuid,
                    'academicyearid' => $academicyearid,
                    'semesterid' => $semesterid,
                    'status' => $status,
                    'usermodified' => $actorid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $saved[] = self::get_selection($id);
            }
        }
        $transaction->allow_commit();

        return ['ok' => true, 'selections' => $saved, 'validation' => $validation];
    }

    /**
     * @return list<array>
     */
    public static function list_student_selections(
        int $userid,
        int $academicyearid = 0,
        int $semesterid = 0,
        ?string $status = 'confirmed'
    ): array {
        global $DB;
        if (!self::tables_ready() || $userid <= 0) {
            return [];
        }
        $params = ['userid' => $userid];
        $where = 'userid = :userid';
        if ($academicyearid > 0) {
            $where .= ' AND academicyearid = :academicyearid';
            $params['academicyearid'] = $academicyearid;
        }
        if ($semesterid > 0) {
            $where .= ' AND semesterid = :semesterid';
            $params['semesterid'] = $semesterid;
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            self::SELECTION_TABLE,
            $where,
            $params,
            'id ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format_selection($row);
        }
        return $out;
    }

    /**
     * Selected course unit IDs for the student in the given institutional period.
     *
     * @return array<int,true>
     */
    public static function selected_courseunit_set(int $userid, int $academicyearid, int $semesterid): array {
        $set = [];
        foreach (self::list_student_selections($userid, $academicyearid, $semesterid, 'confirmed') as $sel) {
            $set[(int)$sel['courseunitid']] = true;
        }
        foreach (self::list_student_selections($userid, $academicyearid, $semesterid, 'selected') as $sel) {
            $set[(int)$sel['courseunitid']] = true;
        }
        return $set;
    }

    /**
     * @param list<int> $courseunitids
     * @return array{ok:bool,errors:list,selectedcount:int,selectedcredits:float,state:string}
     */
    public static function validate_group_selection(array $group, array $courseunitids): array {
        $min = (int)($group['minselect'] ?? 0);
        $max = (int)($group['maxselect'] ?? 0);
        $mincredits = (float)($group['mincredits'] ?? 0);
        $maxcredits = (float)($group['maxcredits'] ?? 0);
        $count = count($courseunitids);
        $credits = 0.0;
        $byid = [];
        foreach ($group['units'] ?? [] as $u) {
            $byid[(int)$u['courseunitid']] = $u;
        }
        foreach ($courseunitids as $id) {
            $credits += (float)($byid[(int)$id]['credithours'] ?? 0);
        }

        $errors = [];
        if ($min > 0 && $count < $min) {
            $errors[] = ['field' => 'selection', 'message' => "Select at least {$min} Course Unit(s)"];
        }
        if ($max > 0 && $count > $max) {
            $errors[] = ['field' => 'selection', 'message' => "Select at most {$max} Course Unit(s)"];
        }
        if ($mincredits > 0 && $credits + 0.0001 < $mincredits) {
            $errors[] = ['field' => 'credits', 'message' => "Select at least {$mincredits} credits"];
        }
        if ($maxcredits > 0 && $credits - 0.0001 > $maxcredits) {
            $errors[] = ['field' => 'credits', 'message' => "Do not exceed {$maxcredits} credits"];
        }

        $state = 'requirement_complete';
        if ($errors) {
            $state = ($max > 0 && $count > $max) ? 'maximum_reached' : 'more_selections_required';
        } else if ($max > 0 && $count >= $max) {
            $state = 'maximum_reached';
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'selectedcount' => $count,
            'selectedcredits' => $credits,
            'state' => $state,
        ];
    }

    public static function format_group(\stdClass $row, bool $withunits = false): array {
        global $DB;
        $out = [
            'id' => (int)$row->id,
            'curriculumid' => (int)$row->curriculumid,
            'yearofstudy' => (int)$row->yearofstudy,
            'semestersequence' => (int)$row->semestersequence,
            'code' => (string)$row->code,
            'name' => (string)$row->name,
            'description' => (string)$row->description,
            'minselect' => (int)$row->minselect,
            'maxselect' => (int)$row->maxselect,
            'mincredits' => (float)$row->mincredits,
            'maxcredits' => (float)$row->maxcredits,
            'status' => (string)$row->status,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'units' => [],
        ];
        if ($withunits) {
            $links = $DB->get_records(self::GROUP_UNIT_TABLE, ['electivegroupid' => (int)$row->id], 'id ASC');
            foreach ($links as $link) {
                $unit = $DB->get_record('local_btiportal_course_unit', ['id' => (int)$link->courseunitid]);
                if (!$unit) {
                    continue;
                }
                $enriched = curriculum_delivery_helper::enrich_unit(academic_course_unit_helper::format($unit));
                $out['units'][] = [
                    'courseunitid' => (int)$unit->id,
                    'code' => (string)$unit->code,
                    'name' => (string)$unit->name,
                    'credithours' => (float)$unit->credithours,
                    'unitstatus' => (string)$unit->status,
                    'requirementtype' => (string)($enriched['requirementtype'] ?? 'elective'),
                    'mappingstatus' => (string)($enriched['mappingstatus'] ?? 'unmapped'),
                    'moodlecourseid' => (int)($enriched['moodlecourseid'] ?? 0),
                    'moodlecoursename' => (string)($enriched['moodlecoursename'] ?? ''),
                ];
            }
        }
        return $out;
    }

    public static function get_selection(int $id): ?array {
        global $DB;
        $row = $DB->get_record(self::SELECTION_TABLE, ['id' => $id]);
        return $row ? self::format_selection($row) : null;
    }

    public static function format_selection(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'electivegroupid' => (int)$row->electivegroupid,
            'courseunitid' => (int)$row->courseunitid,
            'academicyearid' => (int)$row->academicyearid,
            'semesterid' => (int)$row->semesterid,
            'status' => (string)$row->status,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function group_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy'),
            'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'description' => new \core_external\external_value(PARAM_RAW, 'description'),
            'minselect' => new \core_external\external_value(PARAM_INT, 'minselect'),
            'maxselect' => new \core_external\external_value(PARAM_INT, 'maxselect'),
            'mincredits' => new \core_external\external_value(PARAM_FLOAT, 'mincredits'),
            'maxcredits' => new \core_external\external_value(PARAM_FLOAT, 'maxcredits'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'units' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
                    'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'credithours' => new \core_external\external_value(PARAM_FLOAT, 'credithours'),
                    'unitstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'unitstatus'),
                    'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
                    'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
                    'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
                    'moodlecoursename' => new \core_external\external_value(PARAM_TEXT, 'moodlecoursename'),
                ])
            ),
        ];
    }

    public static function selection_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'electivegroupid' => new \core_external\external_value(PARAM_INT, 'electivegroupid'),
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
        ];
    }
}
