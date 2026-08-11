<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Curriculum versions belonging to a Programme.
 * Unrelated to browser localStorage key somas.curriculum.
 */
class curriculum_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create(array $input): array {
        global $DB;

        $programmeid = (int)($input['programmeid'] ?? 0);
        $code = trim((string)($input['code'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $version = trim((string)($input['version'] ?? ''));
        $description = (string)($input['description'] ?? '');
        $status = strtolower(trim((string)($input['status'] ?? 'draft')));
        $effectivefrom = (int)($input['effectivefrom'] ?? 0);
        $effectiveto = (int)($input['effectiveto'] ?? 0);

        $errors = [];
        if ($programmeid <= 0 || !$DB->record_exists('local_btiportal_programme', ['id' => $programmeid])) {
            $errors[] = ['field' => 'programmeid', 'message' => 'Programme not found'];
        }
        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'Curriculum code is required'];
        }
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Curriculum name is required'];
        }
        if ($version === '') {
            $errors[] = ['field' => 'version', 'message' => 'Curriculum version is required'];
        }
        if (!in_array($status, academic_constants::CURRICULUM_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid curriculum status'];
        }
        if ($effectivefrom > 0 && $effectiveto > 0 && $effectivefrom > $effectiveto) {
            $errors[] = ['field' => 'effectivefrom', 'message' => 'effectivefrom must be <= effectiveto'];
        }
        if ($programmeid > 0 && $version !== ''
            && $DB->record_exists('local_btiportal_curriculum', ['programmeid' => $programmeid, 'version' => $version])) {
            $errors[] = ['field' => 'version', 'message' => 'Version already exists for this programme'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $id = $DB->insert_record('local_btiportal_curriculum', (object)[
            'programmeid' => $programmeid,
            'code' => $code,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'status' => $status,
            'effectivefrom' => $effectivefrom,
            'effectiveto' => $effectiveto,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return ['ok' => true, 'record' => self::get($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_curriculum', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Curriculum not found']]];
        }

        $errors = [];
        if (array_key_exists('programmeid', $input)) {
            $pid = (int)$input['programmeid'];
            if ($pid <= 0 || !$DB->record_exists('local_btiportal_programme', ['id' => $pid])) {
                $errors[] = ['field' => 'programmeid', 'message' => 'Programme not found'];
            } else {
                $row->programmeid = $pid;
            }
        }
        if (array_key_exists('code', $input)) {
            $code = trim((string)$input['code']);
            if ($code === '') {
                $errors[] = ['field' => 'code', 'message' => 'Curriculum code is required'];
            } else {
                $row->code = $code;
            }
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'Curriculum name is required'];
            } else {
                $row->name = $name;
            }
        }
        if (array_key_exists('version', $input)) {
            $version = trim((string)$input['version']);
            if ($version === '') {
                $errors[] = ['field' => 'version', 'message' => 'Curriculum version is required'];
            } else {
                $other = $DB->get_record('local_btiportal_curriculum', [
                    'programmeid' => (int)$row->programmeid,
                    'version' => $version,
                ]);
                if ($other && (int)$other->id !== $id) {
                    $errors[] = ['field' => 'version', 'message' => 'Version already exists for this programme'];
                } else {
                    $row->version = $version;
                }
            }
        }
        if (array_key_exists('description', $input)) {
            $row->description = (string)$input['description'];
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::CURRICULUM_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid curriculum status'];
            } else {
                $row->status = $status;
            }
        }
        if (array_key_exists('effectivefrom', $input)) {
            $row->effectivefrom = (int)$input['effectivefrom'];
        }
        if (array_key_exists('effectiveto', $input)) {
            $row->effectiveto = (int)$input['effectiveto'];
        }
        if ((int)$row->effectivefrom > 0 && (int)$row->effectiveto > 0
            && (int)$row->effectivefrom > (int)$row->effectiveto) {
            $errors[] = ['field' => 'effectivefrom', 'message' => 'effectivefrom must be <= effectiveto'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->timemodified = time();
        $DB->update_record('local_btiportal_curriculum', $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Hard-delete a curriculum version. Cascades empty year/semester structure;
     * blocked when course units or student placements still reference it.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function delete(int $id): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_curriculum', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Curriculum not found']]];
        }
        if ($DB->record_exists('local_btiportal_course_unit', ['curriculumid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a curriculum that still has course units. Remove those units first.',
                ]],
            ];
        }
        if ($DB->record_exists('local_btiportal_student_profile', ['curriculumid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a curriculum that is assigned to students.',
                ]],
            ];
        }

        $groups = [];
        if (elective_helper::tables_ready()) {
            $groups = $DB->get_records(elective_helper::GROUP_TABLE, ['curriculumid' => $id], '', 'id');
            foreach ($groups as $group) {
                $gid = (int)$group->id;
                if ($DB->record_exists(elective_helper::SELECTION_TABLE, ['electivegroupid' => $gid])) {
                    return [
                        'ok' => false,
                        'errors' => [[
                            'field' => 'id',
                            'message' => 'Cannot delete a curriculum that has student elective selections.',
                        ]],
                    ];
                }
            }
        }

        $years = $DB->get_records('local_btiportal_curriculum_year', ['curriculumid' => $id], '', 'id');
        foreach ($years as $year) {
            $DB->delete_records('local_btiportal_curriculum_semester', ['curriculumyearid' => (int)$year->id]);
        }
        $DB->delete_records('local_btiportal_curriculum_year', ['curriculumid' => $id]);

        foreach ($groups as $group) {
            $gid = (int)$group->id;
            $DB->delete_records(elective_helper::GROUP_UNIT_TABLE, ['electivegroupid' => $gid]);
        }
        if ($groups) {
            $DB->delete_records(elective_helper::GROUP_TABLE, ['curriculumid' => $id]);
        }

        $formatted = self::format($row);
        $DB->delete_records('local_btiportal_curriculum', ['id' => $id]);
        return ['ok' => true, 'record' => $formatted];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_curriculum', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list(?int $programmeid = null, ?string $status = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($programmeid !== null && $programmeid > 0) {
            $where .= ' AND programmeid = :programmeid';
            $params['programmeid'] = $programmeid;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_curriculum',
            $where,
            $params,
            'programmeid ASC, version ASC',
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

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'programmeid' => (int)$row->programmeid,
            'code' => (string)$row->code,
            'name' => (string)$row->name,
            'version' => (string)$row->version,
            'description' => (string)($row->description ?? ''),
            'status' => (string)$row->status,
            'effectivefrom' => (int)$row->effectivefrom,
            'effectiveto' => (int)$row->effectiveto,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'version' => new \core_external\external_value(PARAM_TEXT, 'version'),
            'description' => new \core_external\external_value(PARAM_RAW, 'description'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'effectivefrom' => new \core_external\external_value(PARAM_INT, 'effectivefrom'),
            'effectiveto' => new \core_external\external_value(PARAM_INT, 'effectiveto'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
        ];
    }
}
