<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Academic year and semester helpers.
 * iscurrent uniqueness is enforced in helpers (MySQL cannot unique-partial easily).
 */
class academic_calendar_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create_year(array $input): array {
        global $DB;

        $name = trim((string)($input['name'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        $startdate = (int)($input['startdate'] ?? 0);
        $enddate = (int)($input['enddate'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? 'upcoming')));
        $iscurrent = !empty($input['iscurrent']) ? 1 : 0;

        $errors = [];
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Academic year name is required'];
        }
        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'Academic year code is required'];
        }
        if ($startdate <= 0 || $enddate <= 0 || $startdate >= $enddate) {
            $errors[] = ['field' => 'startdate', 'message' => 'startdate must be < enddate'];
        }
        if (!in_array($status, academic_constants::YEAR_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid academic year status'];
        }
        if ($name !== '' && $DB->record_exists('local_btiportal_academic_year', ['name' => $name])) {
            $errors[] = ['field' => 'name', 'message' => 'Academic year name already exists'];
        }
        if ($code !== '' && $DB->record_exists('local_btiportal_academic_year', ['code' => $code])) {
            $errors[] = ['field' => 'code', 'message' => 'Academic year code already exists'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($iscurrent) {
                $DB->set_field_select('local_btiportal_academic_year', 'iscurrent', 0, 'iscurrent = 1');
            }
            $now = time();
            $id = $DB->insert_record('local_btiportal_academic_year', (object)[
                'name' => $name,
                'code' => $code,
                'startdate' => $startdate,
                'enddate' => $enddate,
                'status' => $status,
                'iscurrent' => $iscurrent,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        return ['ok' => true, 'record' => self::get_year($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update_year(int $id, array $input): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_academic_year', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Academic year not found']]];
        }

        $errors = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'Academic year name is required'];
            } else {
                $other = $DB->get_record('local_btiportal_academic_year', ['name' => $name]);
                if ($other && (int)$other->id !== $id) {
                    $errors[] = ['field' => 'name', 'message' => 'Academic year name already exists'];
                } else {
                    $row->name = $name;
                }
            }
        }
        if (array_key_exists('code', $input)) {
            $code = trim((string)$input['code']);
            if ($code === '') {
                $errors[] = ['field' => 'code', 'message' => 'Academic year code is required'];
            } else {
                $other = $DB->get_record('local_btiportal_academic_year', ['code' => $code]);
                if ($other && (int)$other->id !== $id) {
                    $errors[] = ['field' => 'code', 'message' => 'Academic year code already exists'];
                } else {
                    $row->code = $code;
                }
            }
        }
        if (array_key_exists('startdate', $input)) {
            $row->startdate = (int)$input['startdate'];
        }
        if (array_key_exists('enddate', $input)) {
            $row->enddate = (int)$input['enddate'];
        }
        if ((int)$row->startdate <= 0 || (int)$row->enddate <= 0 || (int)$row->startdate >= (int)$row->enddate) {
            $errors[] = ['field' => 'startdate', 'message' => 'startdate must be < enddate'];
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::YEAR_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid academic year status'];
            } else {
                $row->status = $status;
            }
        }
        $setcurrent = array_key_exists('iscurrent', $input) ? (!empty($input['iscurrent']) ? 1 : 0) : null;
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($setcurrent === 1) {
                $DB->set_field_select('local_btiportal_academic_year', 'iscurrent', 0, 'iscurrent = 1 AND id <> ?', [$id]);
                $row->iscurrent = 1;
            } else if ($setcurrent === 0) {
                $row->iscurrent = 0;
            }
            $row->timemodified = time();
            $DB->update_record('local_btiportal_academic_year', $row);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        return ['ok' => true, 'record' => self::format_year($row)];
    }

    public static function get_year(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_academic_year', ['id' => $id]);
        return $row ? self::format_year($row) : null;
    }

    /** @return list<array> */
    public static function list_years(?string $status = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_academic_year',
            $where,
            $params,
            'startdate DESC',
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

    public static function format_year(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'name' => (string)$row->name,
            'code' => (string)$row->code,
            'startdate' => (int)$row->startdate,
            'enddate' => (int)$row->enddate,
            'status' => (string)$row->status,
            'iscurrent' => (int)$row->iscurrent,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create_semester(array $input): array {
        global $DB;

        $academicyearid = (int)($input['academicyearid'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        $sequence = (int)($input['sequence'] ?? 0);
        $startdate = (int)($input['startdate'] ?? 0);
        $enddate = (int)($input['enddate'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? 'upcoming')));
        $iscurrent = !empty($input['iscurrent']) ? 1 : 0;

        $errors = [];
        if ($academicyearid <= 0 || !$DB->record_exists('local_btiportal_academic_year', ['id' => $academicyearid])) {
            $errors[] = ['field' => 'academicyearid', 'message' => 'Academic year not found'];
        }
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Semester name is required'];
        }
        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'Semester code is required'];
        }
        if ($sequence <= 0) {
            $errors[] = ['field' => 'sequence', 'message' => 'sequence must be > 0'];
        }
        if ($startdate <= 0 || $enddate <= 0 || $startdate >= $enddate) {
            $errors[] = ['field' => 'startdate', 'message' => 'startdate must be < enddate'];
        }
        if (!in_array($status, academic_constants::SEMESTER_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid semester status'];
        }
        if ($academicyearid > 0 && $sequence > 0
            && $DB->record_exists('local_btiportal_semester', [
                'academicyearid' => $academicyearid,
                'sequence' => $sequence,
            ])) {
            $errors[] = ['field' => 'sequence', 'message' => 'sequence already exists for this academic year'];
        }
        if ($academicyearid > 0 && $startdate > 0 && $enddate > 0) {
            $year = $DB->get_record('local_btiportal_academic_year', ['id' => $academicyearid]);
            if ($year && ($startdate < (int)$year->startdate || $enddate > (int)$year->enddate)) {
                $errors[] = [
                    'field' => 'startdate',
                    'message' => 'Semester dates must fall inside the selected Academic Year',
                ];
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($iscurrent) {
                // Only one institutional semester may be current globally.
                $DB->set_field_select('local_btiportal_semester', 'iscurrent', 0, 'iscurrent = 1');
            }
            $now = time();
            $id = $DB->insert_record('local_btiportal_semester', (object)[
                'academicyearid' => $academicyearid,
                'name' => $name,
                'code' => $code,
                'sequence' => $sequence,
                'startdate' => $startdate,
                'enddate' => $enddate,
                'status' => $status,
                'iscurrent' => $iscurrent,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        return ['ok' => true, 'record' => self::get_semester($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update_semester(int $id, array $input): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_semester', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Semester not found']]];
        }

        $errors = [];
        if (array_key_exists('academicyearid', $input)) {
            $yid = (int)$input['academicyearid'];
            if ($yid <= 0 || !$DB->record_exists('local_btiportal_academic_year', ['id' => $yid])) {
                $errors[] = ['field' => 'academicyearid', 'message' => 'Academic year not found'];
            } else {
                $row->academicyearid = $yid;
            }
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                $errors[] = ['field' => 'name', 'message' => 'Semester name is required'];
            } else {
                $row->name = $name;
            }
        }
        if (array_key_exists('code', $input)) {
            $code = trim((string)$input['code']);
            if ($code === '') {
                $errors[] = ['field' => 'code', 'message' => 'Semester code is required'];
            } else {
                $row->code = $code;
            }
        }
        if (array_key_exists('sequence', $input)) {
            $sequence = (int)$input['sequence'];
            if ($sequence <= 0) {
                $errors[] = ['field' => 'sequence', 'message' => 'sequence must be > 0'];
            } else {
                $other = $DB->get_record('local_btiportal_semester', [
                    'academicyearid' => (int)$row->academicyearid,
                    'sequence' => $sequence,
                ]);
                if ($other && (int)$other->id !== $id) {
                    $errors[] = ['field' => 'sequence', 'message' => 'sequence already exists for this academic year'];
                } else {
                    $row->sequence = $sequence;
                }
            }
        }
        if (array_key_exists('startdate', $input)) {
            $row->startdate = (int)$input['startdate'];
        }
        if (array_key_exists('enddate', $input)) {
            $row->enddate = (int)$input['enddate'];
        }
        if ((int)$row->startdate <= 0 || (int)$row->enddate <= 0 || (int)$row->startdate >= (int)$row->enddate) {
            $errors[] = ['field' => 'startdate', 'message' => 'startdate must be < enddate'];
        }
        $year = $DB->get_record('local_btiportal_academic_year', ['id' => (int)$row->academicyearid]);
        if ($year && ((int)$row->startdate < (int)$year->startdate || (int)$row->enddate > (int)$year->enddate)) {
            $errors[] = [
                'field' => 'startdate',
                'message' => 'Semester dates must fall inside the selected Academic Year',
            ];
        }
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string)$input['status']));
            if (!in_array($status, academic_constants::SEMESTER_STATUSES, true)) {
                $errors[] = ['field' => 'status', 'message' => 'Invalid semester status'];
            } else {
                $row->status = $status;
            }
        }
        $setcurrent = array_key_exists('iscurrent', $input) ? (!empty($input['iscurrent']) ? 1 : 0) : null;
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($setcurrent === 1) {
                // Only one institutional semester may be current globally.
                $DB->set_field_select(
                    'local_btiportal_semester',
                    'iscurrent',
                    0,
                    'iscurrent = 1 AND id <> ?',
                    [$id]
                );
                $row->iscurrent = 1;
            } else if ($setcurrent === 0) {
                $row->iscurrent = 0;
            }
            $row->timemodified = time();
            $DB->update_record('local_btiportal_semester', $row);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        return ['ok' => true, 'record' => self::format_semester($row)];
    }

    public static function get_semester(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_semester', ['id' => $id]);
        return $row ? self::format_semester($row) : null;
    }

    /** @return list<array> */
    public static function list_semesters(?int $academicyearid = null, ?string $status = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($academicyearid !== null && $academicyearid > 0) {
            $where .= ' AND academicyearid = :academicyearid';
            $params['academicyearid'] = $academicyearid;
        }
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_semester',
            $where,
            $params,
            'academicyearid ASC, sequence ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format_semester($row);
        }
        return $out;
    }

    public static function format_semester(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'academicyearid' => (int)$row->academicyearid,
            'name' => (string)$row->name,
            'code' => (string)$row->code,
            'sequence' => (int)$row->sequence,
            'startdate' => (int)$row->startdate,
            'enddate' => (int)$row->enddate,
            'status' => (string)$row->status,
            'iscurrent' => (int)$row->iscurrent,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function year_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'startdate' => new \core_external\external_value(PARAM_INT, 'startdate'),
            'enddate' => new \core_external\external_value(PARAM_INT, 'enddate'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'iscurrent' => new \core_external\external_value(PARAM_INT, 'iscurrent'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
        ];
    }

    public static function semester_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'sequence' => new \core_external\external_value(PARAM_INT, 'sequence'),
            'startdate' => new \core_external\external_value(PARAM_INT, 'startdate'),
            'enddate' => new \core_external\external_value(PARAM_INT, 'enddate'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'iscurrent' => new \core_external\external_value(PARAM_INT, 'iscurrent'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
        ];
    }
}
