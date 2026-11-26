<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Published semester fee schedule plus optional per-course lines.
 */
class semester_fees_helper {
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const DEFAULT_CURRENCY = 'KES';

    public static function line_table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('local_btiportal_semester_fee');
    }

    public static function schedule_table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('local_btiportal_semester_fee_schedule');
    }

    public static function table_exists(): bool {
        return self::line_table_exists() || self::schedule_table_exists();
    }

    /**
     * Parse admin decimal amount (e.g. 450.00) to minor units.
     *
     * @return array{ok:bool,amount?:int,errors?:list}
     */
    public static function parse_usd_decimal(string $raw): array {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            return ['ok' => false, 'errors' => [['field' => 'fee_amount', 'message' => 'Enter a positive amount with at most 2 decimal places']]];
        }
        $float = (float)$raw;
        if ($float <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'fee_amount', 'message' => 'Fee amount must be greater than zero']]];
        }
        $cents = (int) round($float * 100);
        if ($cents <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'fee_amount', 'message' => 'Fee amount must be greater than zero']]];
        }
        return ['ok' => true, 'amount' => $cents];
    }

    public static function get_schedule(int $programmeid, int $academicyearid, int $semesterid, bool $publishedonly = false): ?\stdClass {
        global $DB;
        if (!self::schedule_table_exists()) {
            return null;
        }
        $params = [
            'programmeid' => $programmeid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ];
        if ($publishedonly) {
            $params['status'] = self::STATUS_PUBLISHED;
        }
        return $DB->get_record('local_btiportal_semester_fee_schedule', $params) ?: null;
    }

    public static function has_published_schedule(int $programmeid, int $academicyearid, int $semesterid): bool {
        if (self::schedule_table_exists()) {
            return (bool) self::get_schedule($programmeid, $academicyearid, $semesterid, true);
        }
        if (!self::line_table_exists() || $programmeid <= 0 || $semesterid <= 0) {
            return false;
        }
        global $DB;
        return $DB->record_exists_select(
            'local_btiportal_semester_fee',
            'programmeid = ? AND academicyearid = ? AND semesterid = ? AND status = ?',
            [$programmeid, $academicyearid, $semesterid, self::STATUS_PUBLISHED]
        );
    }

    /**
     * @return array{amount:int,currency:string,due_at:int,published:bool}
     */
    public static function compute_student_total(int $programmeid, int $academicyearid, int $semesterid): array {
        $empty = ['amount' => 0, 'currency' => self::DEFAULT_CURRENCY, 'due_at' => 0, 'published' => false];
        if ($programmeid <= 0 || $semesterid <= 0) {
            return $empty;
        }

        $schedule = self::get_schedule($programmeid, $academicyearid, $semesterid, true);
        if ($schedule) {
            return [
                'amount' => max(0, (int)$schedule->amount),
                'currency' => strtoupper((string)($schedule->currency ?: self::DEFAULT_CURRENCY)),
                'due_at' => (int)$schedule->due_at,
                'published' => true,
            ];
        }

        if (!self::line_table_exists() || !self::has_published_schedule($programmeid, $academicyearid, $semesterid)) {
            return $empty;
        }

        global $DB;
        $rows = $DB->get_records_select(
            'local_btiportal_semester_fee',
            'programmeid = ? AND academicyearid = ? AND semesterid = ? AND status = ?',
            [$programmeid, $academicyearid, $semesterid, self::STATUS_PUBLISHED],
            '',
            'amount, currency, due_at'
        );
        $total = 0;
        $currency = self::DEFAULT_CURRENCY;
        $dueat = 0;
        foreach ($rows as $row) {
            $total += max(0, (int)$row->amount);
            if (!empty($row->currency)) {
                $currency = strtoupper((string)$row->currency);
            }
            $dueat = max($dueat, (int)$row->due_at);
        }
        return [
            'amount' => $total,
            'currency' => $currency,
            'due_at' => $dueat,
            'published' => $total > 0,
        ];
    }

    public static function sum_draft_lines_minor(int $programmeid, int $academicyearid, int $semesterid): int {
        global $DB;
        if (!self::line_table_exists()) {
            return 0;
        }
        $rows = $DB->get_records_select(
            'local_btiportal_semester_fee',
            'programmeid = ? AND academicyearid = ? AND semesterid = ? AND status = ?',
            [$programmeid, $academicyearid, $semesterid, self::STATUS_DRAFT],
            '',
            'amount'
        );
        $total = 0;
        foreach ($rows as $row) {
            $total += max(0, (int)$row->amount);
        }
        return $total;
    }

    /**
     * @return list<array{id:int,courseid:int,coursename:string,amount:int,currency:string,status:string,due_at:int}>
     */
    public static function list_lines(int $programmeid, int $academicyearid, int $semesterid, bool $publishedonly = false): array {
        global $DB;

        if (!self::line_table_exists()) {
            return [];
        }
        $params = [$programmeid, $academicyearid, $semesterid];
        $where = 'programmeid = ? AND academicyearid = ? AND semesterid = ?';
        if ($publishedonly) {
            $where .= ' AND status = ?';
            $params[] = self::STATUS_PUBLISHED;
        }
        $rows = $DB->get_records_select('local_btiportal_semester_fee', $where, $params, 'courseid ASC');
        $items = [];
        foreach ($rows as $row) {
            $course = $DB->get_record('course', ['id' => (int)$row->courseid], 'id,fullname,shortname', IGNORE_MISSING);
            $name = $course ? (string)($course->fullname ?: $course->shortname) : ('Course ' . (int)$row->courseid);
            $items[] = [
                'id' => (int)$row->id,
                'courseid' => (int)$row->courseid,
                'coursename' => $name,
                'amount' => (int)$row->amount,
                'currency' => strtoupper((string)$row->currency),
                'status' => (string)$row->status,
                'due_at' => (int)$row->due_at,
            ];
        }
        return $items;
    }

    /**
     * Courses for a programme semester (curriculum units + any existing fee lines).
     *
     * @return list<array{courseid:int,code:string,coursename:string,amount:int,currency:string,status:string,line_id:int,editable:bool}>
     */
    public static function list_editable_course_fees(int $programmeid, int $academicyearid, int $semesterid): array {
        global $DB;

        if ($programmeid <= 0) {
            return [];
        }

        $publishedschedule = self::get_schedule($programmeid, $academicyearid, $semesterid, true);
        $linesbycourse = [];
        foreach (self::list_lines($programmeid, $academicyearid, $semesterid, false) as $line) {
            $linesbycourse[(int)$line['courseid']] = $line;
        }

        $semestersequence = 0;
        $sem = $DB->get_record('local_btiportal_semester', ['id' => $semesterid], 'id,academicyearid,sequence', IGNORE_MISSING);
        if ($sem && (int)$sem->academicyearid === $academicyearid) {
            $semestersequence = (int)$sem->sequence;
        }

        $params = [$programmeid, 'archived'];
        $where = 'cu.programmeid = ? AND cu.courseid > 1 AND cu.status <> ?';
        if ($semestersequence > 0) {
            $where .= ' AND cu.semestersequence = ?';
            $params[] = $semestersequence;
        }
        $sql = "SELECT cu.courseid, cu.code, cu.name
                  FROM {local_btiportal_course_unit} cu
                 WHERE {$where}
              ORDER BY cu.code ASC";

        $items = [];
        $seen = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $cid = (int)$row->courseid;
            $seen[$cid] = true;
            $line = $linesbycourse[$cid] ?? null;
            $course = $DB->get_record('course', ['id' => $cid], 'fullname', IGNORE_MISSING);
            $items[] = self::admin_course_fee_row(
                $cid,
                (string)$row->code,
                $course ? (string)$course->fullname : (string)$row->name,
                $line,
                (bool)$publishedschedule
            );
        }

        foreach ($linesbycourse as $cid => $line) {
            if (isset($seen[$cid])) {
                continue;
            }
            $items[] = self::admin_course_fee_row(
                $cid,
                '',
                (string)$line['coursename'],
                $line,
                (bool)$publishedschedule
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed>|null $line
     * @return array{courseid:int,code:string,coursename:string,amount:int,currency:string,status:string,line_id:int,editable:bool}
     */
    private static function admin_course_fee_row(
        int $courseid,
        string $code,
        string $name,
        ?array $line,
        bool $schedulepublished
    ): array {
        unset($schedulepublished);
        $status = $line ? (string)$line['status'] : '';
        if ($status === '') {
            $status = 'not_set';
        }
        return [
            'courseid' => $courseid,
            'code' => $code,
            'coursename' => $name,
            'amount' => $line ? (int)$line['amount'] : 0,
            'currency' => $line ? (string)$line['currency'] : self::DEFAULT_CURRENCY,
            'status' => $status,
            'line_id' => $line ? (int)$line['id'] : 0,
            'editable' => true,
        ];
    }

    /**
     * @return list<array{courseid:int,coursename:string,code:string}>
     */
    public static function suggest_courses_for_programme(int $programmeid): array {
        global $DB;
        if ($programmeid <= 0) {
            return [];
        }
        $sql = "SELECT DISTINCT cu.courseid, cu.code, cu.name
                  FROM {local_btiportal_course_unit} cu
                 WHERE cu.programmeid = ?
                   AND cu.courseid > 1
                   AND cu.status <> 'archived'
                 ORDER BY cu.code ASC";
        $rows = $DB->get_records_sql($sql, [$programmeid]);
        $items = [];
        foreach ($rows as $row) {
            $course = $DB->get_record('course', ['id' => (int)$row->courseid], 'id,fullname', IGNORE_MISSING);
            $items[] = [
                'courseid' => (int)$row->courseid,
                'coursename' => $course ? (string)$course->fullname : (string)$row->name,
                'code' => (string)$row->code,
            ];
        }
        return $items;
    }

    /**
     * @return array{ok:bool,errors?:list}
     */
    public static function upsert_line(
        int $programmeid,
        int $academicyearid,
        int $semesterid,
        int $courseid,
        int $amountminor,
        string $currency,
        int $dueat,
        int $actorid
    ): array {
        global $DB;

        if (!self::line_table_exists()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Semester fees not installed']]];
        }
        if ($programmeid <= 0 || $semesterid <= 0 || $courseid <= 1 || $amountminor < 0) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Invalid fee line']]];
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Course not found']]];
        }

        $existing = $DB->get_record('local_btiportal_semester_fee', [
            'programmeid' => $programmeid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'courseid' => $courseid,
        ]);
        $now = time();
        $currency = strtoupper(trim($currency) ?: self::DEFAULT_CURRENCY);

        if ($existing) {
            $existing->amount = $amountminor;
            $existing->currency = $currency;
            $existing->due_at = max(0, $dueat);
            if ((string)$existing->status !== self::STATUS_PUBLISHED) {
                $existing->status = self::STATUS_DRAFT;
            }
            $existing->timemodified = $now;
            $existing->modifiedby = $actorid;
            $DB->update_record('local_btiportal_semester_fee', $existing);
        } else {
            $DB->insert_record('local_btiportal_semester_fee', (object) [
                'programmeid' => $programmeid,
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
                'courseid' => $courseid,
                'amount' => $amountminor,
                'currency' => $currency,
                'due_at' => max(0, $dueat),
                'status' => self::STATUS_DRAFT,
                'createdby' => $actorid,
                'modifiedby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        return ['ok' => true];
    }

    /**
     * Remove a per-course fee line (draft or published).
     *
     * @return array{ok:bool,errors?:list}
     */
    public static function delete_line(
        int $programmeid,
        int $academicyearid,
        int $semesterid,
        int $courseid
    ): array {
        global $DB;

        if (!self::line_table_exists()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Semester fees not installed']]];
        }
        if ($programmeid <= 0 || $semesterid <= 0 || $courseid <= 1) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Invalid parameters']]];
        }

        $deleted = $DB->delete_records('local_btiportal_semester_fee', [
            'programmeid' => $programmeid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'courseid' => $courseid,
        ]);

        if (!$deleted) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'No fee line to delete']]];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,schedule?:array,errors?:list}
     */
    public static function admin_payload(int $programmeid, int $academicyearid, int $semesterid): array {
        $schedule = self::get_schedule($programmeid, $academicyearid, $semesterid, false);
        $lines = self::list_lines($programmeid, $academicyearid, $semesterid, false);
        $linestotal = 0;
        foreach ($lines as $line) {
            if ($line['status'] === self::STATUS_DRAFT) {
                $linestotal += (int)$line['amount'];
            }
        }
        $sched = null;
        if ($schedule) {
            $sched = [
                'amount' => (int)$schedule->amount,
                'currency' => strtoupper((string)$schedule->currency),
                'due_at' => (int)$schedule->due_at,
                'status' => (string)$schedule->status,
            ];
        }
        return [
            'schedule' => $sched,
            'lines' => $lines,
            'draft_lines_total' => $linestotal,
            'suggested_courses' => self::suggest_courses_for_programme($programmeid),
            'courses' => self::list_editable_course_fees($programmeid, $academicyearid, $semesterid),
        ];
    }

    /**
     * @return array{ok:bool,published?:int,students?:int,errors?:list}
     */
    public static function publish(
        int $programmeid,
        int $academicyearid,
        int $semesterid,
        string $feeamountdecimal,
        int $dueat,
        int $actorid
    ): array {
        global $DB;

        if (!self::schedule_table_exists()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Semester fee schedule not installed']]];
        }

        $parsed = self::parse_usd_decimal($feeamountdecimal);
        if (!$parsed['ok']) {
            return $parsed;
        }
        $amountminor = (int)$parsed['amount'];

        $drafttotal = self::sum_draft_lines_minor($programmeid, $academicyearid, $semesterid);
        if ($drafttotal > 0 && $drafttotal !== $amountminor) {
            return ['ok' => false, 'errors' => [[
                'field' => 'fee_amount',
                'message' => 'Fee amount must equal the draft course-line total',
            ]]];
        }

        $now = time();
        $currency = paystack_client::currency_for_payment('XXX');
        if ($currency === '') {
            $currency = self::resolve_schedule_currency($programmeid, $academicyearid, $semesterid);
        }
        $existing = self::get_schedule($programmeid, $academicyearid, $semesterid, false);
        if ($existing) {
            $existing->amount = $amountminor;
            $existing->currency = $currency;
            $existing->due_at = max(0, $dueat);
            $existing->status = self::STATUS_PUBLISHED;
            $existing->modifiedby = $actorid;
            $existing->timemodified = $now;
            $DB->update_record('local_btiportal_semester_fee_schedule', $existing);
        } else {
            $DB->insert_record('local_btiportal_semester_fee_schedule', (object) [
                'programmeid' => $programmeid,
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
                'amount' => $amountminor,
                'currency' => $currency,
                'due_at' => max(0, $dueat),
                'status' => self::STATUS_PUBLISHED,
                'createdby' => $actorid,
                'modifiedby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $publishedlines = 0;
        if (self::line_table_exists()) {
            $drafts = $DB->get_records_select(
                'local_btiportal_semester_fee',
                'programmeid = ? AND academicyearid = ? AND semesterid = ? AND status = ?',
                [$programmeid, $academicyearid, $semesterid, self::STATUS_DRAFT]
            );
            foreach ($drafts as $row) {
                $row->status = self::STATUS_PUBLISHED;
                $row->due_at = max((int)$row->due_at, $dueat);
                $row->modifiedby = $actorid;
                $row->timemodified = $now;
                $DB->update_record('local_btiportal_semester_fee', $row);
                $publishedlines++;
            }
        }

        $students = student_semester_fees_helper::ensure_unpaid_for_programme_period(
            $programmeid,
            $academicyearid,
            $semesterid,
            max(0, $dueat),
            $amountminor,
            $currency
        );

        return ['ok' => true, 'published' => max(1, $publishedlines), 'students' => $students];
    }

    /**
     * Currency for a programme semester: keep schedule, else first fee line, else default.
     */
    private static function resolve_schedule_currency(int $programmeid, int $academicyearid, int $semesterid): string {
        global $DB;

        $existing = self::get_schedule($programmeid, $academicyearid, $semesterid, false);
        if ($existing && trim((string) $existing->currency) !== '') {
            return strtoupper(trim((string) $existing->currency));
        }

        if (self::line_table_exists()) {
            $lines = $DB->get_records_select(
                'local_btiportal_semester_fee',
                'programmeid = ? AND academicyearid = ? AND semesterid = ?',
                [$programmeid, $academicyearid, $semesterid],
                'id ASC',
                'id,currency'
            );
            foreach ($lines as $line) {
                if (trim((string) $line->currency) !== '') {
                    return strtoupper(trim((string) $line->currency));
                }
            }
        }

        return self::DEFAULT_CURRENCY;
    }

    /**
     * @return list<array{academicyearid:int,semesterid:int,academic_year:string,semester:string}>
     */
    public static function list_published_periods_for_programme(int $programmeid): array {
        global $DB;
        if (!self::schedule_table_exists() || $programmeid <= 0) {
            return [];
        }
        $sql = "SELECT sch.academicyearid, sch.semesterid, sch.amount, sch.currency, sch.due_at,
                       ay.name AS yearname, ay.startdate AS yearstart,
                       sem.name AS semestername, sem.sequence AS semsequence
                  FROM {local_btiportal_semester_fee_schedule} sch
             LEFT JOIN {local_btiportal_academic_year} ay ON ay.id = sch.academicyearid
             LEFT JOIN {local_btiportal_semester} sem ON sem.id = sch.semesterid
                 WHERE sch.programmeid = ? AND sch.status = ?
              ORDER BY ay.startdate DESC, sem.sequence DESC";
        $rows = $DB->get_records_sql($sql, [$programmeid, self::STATUS_PUBLISHED]);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'academicyearid' => (int)$row->academicyearid,
                'semesterid' => (int)$row->semesterid,
                'academic_year' => (string)($row->yearname ?? ''),
                'semester' => (string)($row->semestername ?? ''),
                'amount' => (int)$row->amount,
                'currency' => strtoupper((string)$row->currency),
                'due_at' => (int)$row->due_at,
            ];
        }
        return $items;
    }
}