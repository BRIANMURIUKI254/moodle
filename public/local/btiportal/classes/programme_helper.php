<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Programme CRUD (plugin table only).
 *
 * departmentid stays optional (0 = unassigned) for Phase 2 rows, but any
 * non-zero value must reference an existing department.
 */
class programme_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function create(array $input, int $userid = 0): array {
        global $DB;

        $code = trim((string)($input['code'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = (string)($input['description'] ?? '');
        $departmentid = (int)($input['departmentid'] ?? 0);
        $moodlecategoryid = (int)($input['moodlecategoryid'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $awardtype = strtolower(trim((string)($input['awardtype'] ?? 'bachelor')));
        $duration = (float)($input['duration'] ?? 0);
        $durationunit = strtolower(trim((string)($input['durationunit'] ?? 'years')));

        $errors = [];
        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'Programme code is required'];
        }
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Programme name is required'];
        }
        if (!in_array($status, academic_constants::PROGRAMME_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid programme status'];
        }
        if (!in_array($awardtype, academic_constants::AWARD_TYPES, true)) {
            $errors[] = ['field' => 'awardtype', 'message' => 'Invalid award type'];
        }
        if (!in_array($durationunit, academic_constants::DURATION_UNITS, true)) {
            $errors[] = ['field' => 'durationunit', 'message' => 'Invalid duration unit'];
        }
        if ($duration < 0) {
            $errors[] = ['field' => 'duration', 'message' => 'Duration cannot be negative'];
        }
        if ($departmentid > 0 && !department_helper::exists($departmentid)) {
            $errors[] = ['field' => 'departmentid', 'message' => 'Department not found'];
        }
        if ($moodlecategoryid > 0 && !$DB->record_exists('course_categories', ['id' => $moodlecategoryid])) {
            $errors[] = ['field' => 'moodlecategoryid', 'message' => 'Moodle category does not exist'];
        }
        if ($code !== '' && $DB->record_exists('local_btiportal_programme', ['code' => $code])) {
            $errors[] = ['field' => 'code', 'message' => 'Programme code already exists'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $id = $DB->insert_record('local_btiportal_programme', (object)[
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'departmentid' => $departmentid,
            'moodlecategoryid' => $moodlecategoryid,
            'status' => $status,
            'awardtype' => $awardtype,
            'duration' => $duration,
            'durationunit' => $durationunit,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);

        return ['ok' => true, 'record' => self::get($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input, int $userid = 0): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_programme', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Programme not found']]];
        }

        $errors = [];
        if (array_key_exists('code', $input)) {
            $code = trim((string)$input['code']);
            if ($code === '') {
                $errors[] = ['field' => 'code', 'message' => 'Programme code is required'];
            } else {
                $other = $DB->get_record('local_btiportal_programme', ['code' => $code]);
                if ($other && (int)$other->id !== $id) {
                    $errors[] = ['field' => 'code', 'message' => 'Programme code already exists'];
                } else {
                    $row->code = $code;
                }
            }
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'Programme name is required'];
            } else {
                $row->name = $name;
            }
        }
        if (array_key_exists('description', $input)) {
            $row->description = (string)$input['description'];
        }
        if (array_key_exists('departmentid', $input)) {
            $departmentid = (int)$input['departmentid'];
            if ($departmentid > 0 && !department_helper::exists($departmentid)) {
                $errors[] = ['field' => 'departmentid', 'message' => 'Department not found'];
            } else {
                $row->departmentid = $departmentid;
            }
        }
        if (array_key_exists('awardtype', $input)) {
            $awardtype = strtolower(trim((string)$input['awardtype']));
            if (!in_array($awardtype, academic_constants::AWARD_TYPES, true)) {
                $errors[] = ['field' => 'awardtype', 'message' => 'Invalid award type'];
            } else {
                $row->awardtype = $awardtype;
            }
        }
        if (array_key_exists('duration', $input)) {
            $duration = (float)$input['duration'];
            if ($duration < 0) {
                $errors[] = ['field' => 'duration', 'message' => 'Duration cannot be negative'];
            } else {
                $row->duration = $duration;
            }
        }
        if (array_key_exists('durationunit', $input)) {
            $durationunit = strtolower(trim((string)$input['durationunit']));
            if (!in_array($durationunit, academic_constants::DURATION_UNITS, true)) {
                $errors[] = ['field' => 'durationunit', 'message' => 'Invalid duration unit'];
            } else {
                $row->durationunit = $durationunit;
            }
        }
        if (array_key_exists('moodlecategoryid', $input)) {
            $cat = (int)$input['moodlecategoryid'];
            if ($cat > 0 && !$DB->record_exists('course_categories', ['id' => $cat])) {
                $errors[] = ['field' => 'moodlecategoryid', 'message' => 'Moodle category does not exist'];
            } else {
                $row->moodlecategoryid = $cat;
            }
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::PROGRAMME_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid programme status'];
            } else {
                $row->status = $status;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_programme', $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Hard-delete a programme (Course). Blocked while curricula remain.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function delete(int $id): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_programme', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course not found']]];
        }
        if ($DB->record_exists('local_btiportal_curriculum', ['programmeid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a course that still has curriculum versions. Delete those curricula first.',
                ]],
            ];
        }
        if ($DB->record_exists('local_btiportal_course_unit', ['programmeid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a course that still has course units. Remove those units first.',
                ]],
            ];
        }

        $formatted = self::format($row);
        $DB->delete_records('local_btiportal_programme', ['id' => $id]);
        return ['ok' => true, 'record' => $formatted];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_programme', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list(?string $status = null, ?int $departmentid = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        if ($departmentid !== null && $departmentid > 0) {
            $where .= ' AND departmentid = :departmentid';
            $params['departmentid'] = $departmentid;
        }
        $rows = $DB->get_records_select(
            'local_btiportal_programme',
            $where,
            $params,
            'name ASC',
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
            'code' => (string)$row->code,
            'name' => (string)$row->name,
            'description' => (string)($row->description ?? ''),
            'departmentid' => (int)$row->departmentid,
            'moodlecategoryid' => (int)$row->moodlecategoryid,
            'status' => (string)$row->status,
            'awardtype' => (string)($row->awardtype ?? 'bachelor'),
            'duration' => (float)($row->duration ?? 0),
            'durationunit' => (string)($row->durationunit ?? 'years'),
            'imageurl' => (string)($row->imageurl ?? ''),
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)($row->usermodified ?? 0),
        ];
    }

    public static function programme_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'description' => new \core_external\external_value(PARAM_RAW, 'description'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'owning department (0 = unassigned)'),
            'moodlecategoryid' => new \core_external\external_value(PARAM_INT, 'optional moodle category'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'awardtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'awardtype'),
            'duration' => new \core_external\external_value(PARAM_FLOAT, 'duration'),
            'durationunit' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'durationunit'),
            'imageurl' => new \core_external\external_value(PARAM_TEXT, 'imageurl', VALUE_OPTIONAL),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }
}
