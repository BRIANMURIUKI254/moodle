<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * School CRUD (plugin table only). Top level of the academic org tree.
 */
class school_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function create(array $input, int $userid = 0): array {
        global $DB;

        $code = trim((string)($input['code'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = (string)($input['description'] ?? '');
        $status = strtolower(trim((string)($input['status'] ?? 'active')));

        $errors = [];
        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'School code is required'];
        }
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'School name is required'];
        }
        if (!in_array($status, academic_constants::SCHOOL_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid school status'];
        }
        if ($code !== '' && $DB->record_exists('local_btiportal_school', ['code' => $code])) {
            $errors[] = ['field' => 'code', 'message' => 'School code already exists'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $id = $DB->insert_record('local_btiportal_school', (object)[
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'status' => $status,
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

        $row = $DB->get_record('local_btiportal_school', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'School not found']]];
        }

        $errors = [];
        if (array_key_exists('code', $input)) {
            $code = trim((string)$input['code']);
            if ($code === '') {
                $errors[] = ['field' => 'code', 'message' => 'School code is required'];
            } else {
                $other = $DB->get_record('local_btiportal_school', ['code' => $code]);
                if ($other && (int)$other->id !== $id) {
                    $errors[] = ['field' => 'code', 'message' => 'School code already exists'];
                } else {
                    $row->code = $code;
                }
            }
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'School name is required'];
            } else {
                $row->name = $name;
            }
        }
        if (array_key_exists('description', $input)) {
            $row->description = (string)$input['description'];
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::SCHOOL_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid school status'];
            } else {
                $row->status = $status;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_school', $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Hard-delete a school. Blocked while any department still references it.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function delete(int $id): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_school', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'School not found']]];
        }
        if ($DB->record_exists('local_btiportal_department', ['schoolid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a school that still has departments. Delete or move those departments first.',
                ]],
            ];
        }

        $formatted = self::format($row);
        $DB->delete_records('local_btiportal_school', ['id' => $id]);
        return ['ok' => true, 'record' => $formatted];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_school', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list(?string $status = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_school',
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

    public static function exists_active(int $id): bool {
        global $DB;
        return $id > 0 && $DB->record_exists('local_btiportal_school', ['id' => $id, 'status' => 'active']);
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'code' => (string)$row->code,
            'name' => (string)$row->name,
            'description' => (string)($row->description ?? ''),
            'status' => (string)$row->status,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)($row->usermodified ?? 0),
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'description' => new \core_external\external_value(PARAM_RAW, 'description'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }
}
