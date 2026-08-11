<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Curriculum internal structure: Year of Study and Curriculum Semester.
 *
 * These are curriculum-scoped templates and are deliberately separate from the
 * institutional calendar (local_btiportal_academic_year / _semester).
 */
class curriculum_structure_helper {
    // ===== Year of study =====

    /**
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function create_year(array $input, int $userid = 0): array {
        global $DB;

        $curriculumid = (int)($input['curriculumid'] ?? 0);
        $yearnumber = (int)($input['yearnumber'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $description = (string)($input['description'] ?? '');
        $status = strtolower(trim((string)($input['status'] ?? 'active')));

        $errors = [];
        if ($curriculumid <= 0 || !$DB->record_exists('local_btiportal_curriculum', ['id' => $curriculumid])) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Curriculum not found'];
        }
        if ($yearnumber <= 0) {
            $errors[] = ['field' => 'yearnumber', 'message' => 'yearnumber must be > 0'];
        }
        if (!in_array($status, academic_constants::YEAR_OF_STUDY_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid year of study status'];
        }
        if ($curriculumid > 0 && $yearnumber > 0 && $DB->record_exists('local_btiportal_curriculum_year', [
            'curriculumid' => $curriculumid,
            'yearnumber' => $yearnumber,
        ])) {
            $errors[] = ['field' => 'yearnumber', 'message' => 'yearnumber already exists for this curriculum'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        if ($name === '') {
            $name = 'Year ' . $yearnumber;
        }

        $now = time();
        $id = $DB->insert_record('local_btiportal_curriculum_year', (object)[
            'curriculumid' => $curriculumid,
            'yearnumber' => $yearnumber,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);

        return ['ok' => true, 'record' => self::get_year($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update_year(int $id, array $input, int $userid = 0): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_curriculum_year', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Year of study not found']]];
        }

        $errors = [];
        if (array_key_exists('yearnumber', $input)) {
            $yearnumber = (int)$input['yearnumber'];
            if ($yearnumber <= 0) {
                $errors[] = ['field' => 'yearnumber', 'message' => 'yearnumber must be > 0'];
            } else {
                $dup = $DB->get_record('local_btiportal_curriculum_year', [
                    'curriculumid' => (int)$row->curriculumid,
                    'yearnumber' => $yearnumber,
                ]);
                if ($dup && (int)$dup->id !== $id) {
                    $errors[] = ['field' => 'yearnumber', 'message' => 'yearnumber already exists for this curriculum'];
                } else {
                    $row->yearnumber = $yearnumber;
                }
            }
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'Name is required'];
            } else {
                $row->name = $name;
            }
        }
        if (array_key_exists('description', $input)) {
            $row->description = (string)$input['description'];
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::YEAR_OF_STUDY_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid year of study status'];
            } else {
                $row->status = $status;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_curriculum_year', $row);
        return ['ok' => true, 'record' => self::format_year($row)];
    }

    public static function get_year(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_curriculum_year', ['id' => $id]);
        return $row ? self::format_year($row) : null;
    }

    /**
     * @return list<array>
     */
    public static function list_years(?int $curriculumid = null, ?string $status = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($curriculumid !== null && $curriculumid > 0) {
            $where .= ' AND curriculumid = :curriculumid';
            $params['curriculumid'] = $curriculumid;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_curriculum_year',
            $where,
            $params,
            'curriculumid ASC, yearnumber ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format_year($row);
        }
        return $out;
    }

    // ===== Curriculum semester =====

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create_semester(array $input, int $userid = 0): array {
        global $DB;

        $curriculumyearid = (int)($input['curriculumyearid'] ?? 0);
        $semesternumber = (int)($input['semesternumber'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $creditrequirement = (float)($input['creditrequirement'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? 'active')));

        $errors = [];
        if ($curriculumyearid <= 0 || !$DB->record_exists('local_btiportal_curriculum_year', ['id' => $curriculumyearid])) {
            $errors[] = ['field' => 'curriculumyearid', 'message' => 'Year of study not found'];
        }
        if ($semesternumber <= 0) {
            $errors[] = ['field' => 'semesternumber', 'message' => 'semesternumber must be > 0'];
        }
        if ($creditrequirement < 0) {
            $errors[] = ['field' => 'creditrequirement', 'message' => 'creditrequirement cannot be negative'];
        }
        if (!in_array($status, academic_constants::CURRICULUM_SEMESTER_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid curriculum semester status'];
        }
        if ($curriculumyearid > 0 && $semesternumber > 0 && $DB->record_exists('local_btiportal_curriculum_semester', [
            'curriculumyearid' => $curriculumyearid,
            'semesternumber' => $semesternumber,
        ])) {
            $errors[] = ['field' => 'semesternumber', 'message' => 'semesternumber already exists for this year of study'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        if ($name === '') {
            $name = 'Semester ' . $semesternumber;
        }

        $now = time();
        $id = $DB->insert_record('local_btiportal_curriculum_semester', (object)[
            'curriculumyearid' => $curriculumyearid,
            'semesternumber' => $semesternumber,
            'name' => $name,
            'creditrequirement' => $creditrequirement,
            'status' => $status,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);

        return ['ok' => true, 'record' => self::get_semester($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update_semester(int $id, array $input, int $userid = 0): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Curriculum semester not found']]];
        }

        $errors = [];
        if (array_key_exists('semesternumber', $input)) {
            $semesternumber = (int)$input['semesternumber'];
            if ($semesternumber <= 0) {
                $errors[] = ['field' => 'semesternumber', 'message' => 'semesternumber must be > 0'];
            } else {
                $dup = $DB->get_record('local_btiportal_curriculum_semester', [
                    'curriculumyearid' => (int)$row->curriculumyearid,
                    'semesternumber' => $semesternumber,
                ]);
                if ($dup && (int)$dup->id !== $id) {
                    $errors[] = [
                        'field' => 'semesternumber',
                        'message' => 'semesternumber already exists for this year of study',
                    ];
                } else {
                    $row->semesternumber = $semesternumber;
                }
            }
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'Name is required'];
            } else {
                $row->name = $name;
            }
        }
        if (array_key_exists('creditrequirement', $input)) {
            $credit = (float)$input['creditrequirement'];
            if ($credit < 0) {
                $errors[] = ['field' => 'creditrequirement', 'message' => 'creditrequirement cannot be negative'];
            } else {
                $row->creditrequirement = $credit;
            }
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::CURRICULUM_SEMESTER_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid curriculum semester status'];
            } else {
                $row->status = $status;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->timemodified = time();
        $row->usermodified = $userid;
        $DB->update_record('local_btiportal_curriculum_semester', $row);
        return ['ok' => true, 'record' => self::format_semester($row)];
    }

    public static function get_semester(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $id]);
        return $row ? self::format_semester($row) : null;
    }

    /**
     * When $curriculumid is given, all semesters across that curriculum's years are returned.
     *
     * @return list<array>
     */
    public static function list_semesters(
        ?int $curriculumyearid = null,
        ?int $curriculumid = null,
        ?string $status = null
    ): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($curriculumyearid !== null && $curriculumyearid > 0) {
            $where .= ' AND cs.curriculumyearid = :curriculumyearid';
            $params['curriculumyearid'] = $curriculumyearid;
        }
        if ($curriculumid !== null && $curriculumid > 0) {
            $where .= ' AND cy.curriculumid = :curriculumid';
            $params['curriculumid'] = $curriculumid;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND cs.status = :status';
            $params['status'] = strtolower($status);
        }
        $sql = "SELECT cs.*
                  FROM {local_btiportal_curriculum_semester} cs
                  JOIN {local_btiportal_curriculum_year} cy ON cy.id = cs.curriculumyearid
                 WHERE {$where}
              ORDER BY cy.curriculumid ASC, cy.yearnumber ASC, cs.semesternumber ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, academic_constants::LIST_LIMIT);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format_semester($row);
        }
        return $out;
    }

    public static function format_year(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'curriculumid' => (int)$row->curriculumid,
            'yearnumber' => (int)$row->yearnumber,
            'name' => (string)$row->name,
            'description' => (string)($row->description ?? ''),
            'status' => (string)$row->status,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)($row->usermodified ?? 0),
        ];
    }

    public static function format_semester(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'curriculumyearid' => (int)$row->curriculumyearid,
            'semesternumber' => (int)$row->semesternumber,
            'name' => (string)$row->name,
            'creditrequirement' => (float)$row->creditrequirement,
            'status' => (string)$row->status,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'usermodified' => (int)($row->usermodified ?? 0),
        ];
    }

    public static function year_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'yearnumber' => new \core_external\external_value(PARAM_INT, 'yearnumber'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'description' => new \core_external\external_value(PARAM_RAW, 'description'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }

    public static function semester_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
            'semesternumber' => new \core_external\external_value(PARAM_INT, 'semesternumber'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'creditrequirement' => new \core_external\external_value(PARAM_FLOAT, 'creditrequirement'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'usermodified' => new \core_external\external_value(PARAM_INT, 'usermodified'),
        ];
    }
}
