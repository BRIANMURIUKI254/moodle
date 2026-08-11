<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Department CRUD. Every department belongs to exactly one school.
 */
class department_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function create(array $input, int $userid = 0): array {
        global $DB;

        $schoolid = (int)($input['schoolid'] ?? 0);
        $code = trim((string)($input['code'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = (string)($input['description'] ?? '');
        $status = strtolower(trim((string)($input['status'] ?? 'active')));

        $errors = [];
        if ($schoolid <= 0) {
            $errors[] = ['field' => 'schoolid', 'message' => 'School is required'];
        } else {
            $school = $DB->get_record('local_btiportal_school', ['id' => $schoolid]);
            if (!$school) {
                $errors[] = ['field' => 'schoolid', 'message' => 'School not found'];
            } else if ((string)$school->status !== 'active') {
                $errors[] = ['field' => 'schoolid', 'message' => 'School is not active'];
            }
        }
        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'Department code is required'];
        }
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Department name is required'];
        }
        if (!in_array($status, academic_constants::DEPARTMENT_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid department status'];
        }
        if ($code !== '' && $DB->record_exists('local_btiportal_department', ['code' => $code])) {
            $errors[] = ['field' => 'code', 'message' => 'Department code already exists'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $id = $DB->insert_record('local_btiportal_department', (object)[
            'schoolid' => $schoolid,
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

        $row = $DB->get_record('local_btiportal_department', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Department not found']]];
        }

        $errors = [];
        if (array_key_exists('schoolid', $input)) {
            $schoolid = (int)$input['schoolid'];
            $school = $schoolid > 0 ? $DB->get_record('local_btiportal_school', ['id' => $schoolid]) : false;
            if (!$school) {
                $errors[] = ['field' => 'schoolid', 'message' => 'School not found'];
            } else if ((string)$school->status !== 'active') {
                $errors[] = ['field' => 'schoolid', 'message' => 'School is not active'];
            } else {
                $row->schoolid = $schoolid;
            }
        }
        if (array_key_exists('code', $input)) {
            $code = trim((string)$input['code']);
            if ($code === '') {
                $errors[] = ['field' => 'code', 'message' => 'Department code is required'];
            } else {
                $other = $DB->get_record('local_btiportal_department', ['code' => $code]);
                if ($other && (int)$other->id !== $id) {
                    $errors[] = ['field' => 'code', 'message' => 'Department code already exists'];
                } else {
                    $row->code = $code;
                }
            }
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'Department name is required'];
            } else {
                $row->name = $name;
            }
        }
        if (array_key_exists('description', $input)) {
            $row->description = (string)$input['description'];
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::DEPARTMENT_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid department status'];
            } else {
                $row->status = $status;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_department', $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Hard-delete a department. Blocked while programmes or lecturer memberships remain.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function delete(int $id): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_department', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Department not found']]];
        }
        if ($DB->record_exists('local_btiportal_programme', ['departmentid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a department that still has courses. Delete those courses first.',
                ]],
            ];
        }
        if ($DB->record_exists('local_btiportal_lecturer_dept', ['departmentid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a department that still has lecturer memberships. Remove them first.',
                ]],
            ];
        }
        if ($DB->record_exists('local_btiportal_staff_org', ['departmentid' => $id])) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'id',
                    'message' => 'Cannot delete a department that still has staff assignments. Remove them first.',
                ]],
            ];
        }

        $formatted = self::format($row);
        $DB->delete_records('local_btiportal_department', ['id' => $id]);
        return ['ok' => true, 'record' => $formatted];
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_department', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list(?int $schoolid = null, ?string $status = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($schoolid !== null && $schoolid > 0) {
            $where .= ' AND schoolid = :schoolid';
            $params['schoolid'] = $schoolid;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_department',
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

    public static function exists(int $id): bool {
        global $DB;
        return $id > 0 && $DB->record_exists('local_btiportal_department', ['id' => $id]);
    }

    public static function exists_active(int $id): bool {
        global $DB;
        return $id > 0 && $DB->record_exists('local_btiportal_department', ['id' => $id, 'status' => 'active']);
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'schoolid' => (int)$row->schoolid,
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
            'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
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
