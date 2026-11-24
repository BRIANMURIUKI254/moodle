<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-student per-institutional-semester tuition obligation and paid status.
 */
class student_semester_fees_helper {
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PAID = 'paid';

    public static function table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('local_btiportal_student_semester_fees');
    }

    /**
     * @return array{programmeid:int,academicyearid:int,semesterid:int}|null
     */
    public static function resolve_current_period(int $userid): ?array {
        global $DB;

        $raw = student_profile_helper::get_by_userid($userid);
        $programmeid = $raw ? (int)($raw['programmeid'] ?? 0) : 0;
        if ($programmeid <= 0) {
            return null;
        }

        $academicyearid = $raw ? (int)($raw['academicyearid'] ?? 0) : 0;
        $semesterid = $raw ? (int)($raw['semesterid'] ?? 0) : 0;

        if ($semesterid <= 0 || $academicyearid <= 0) {
            $current = $DB->get_record('local_btiportal_semester', ['iscurrent' => 1], '*', IGNORE_MISSING);
            if ($current) {
                $semesterid = $semesterid > 0 ? $semesterid : (int)$current->id;
                $academicyearid = $academicyearid > 0 ? $academicyearid : (int)$current->academicyearid;
            }
        }

        if ($semesterid <= 0 || $academicyearid <= 0) {
            return null;
        }

        return [
            'programmeid' => $programmeid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ];
    }

    public static function get_row(int $userid, int $academicyearid, int $semesterid): ?\stdClass {
        global $DB;
        if (!self::table_exists() || $userid <= 0) {
            return null;
        }
        return $DB->get_record('local_btiportal_student_semester_fees', [
            'userid' => $userid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ]) ?: null;
    }

    public static function is_current_semester_paid(int $userid): bool {
        if (!self::table_exists()) {
            return student_fees_helper::get_status($userid)['status'] === student_fees_helper::STATUS_PAID;
        }

        $period = self::resolve_current_period($userid);
        if (!$period) {
            return student_fees_helper::get_status($userid)['status'] === student_fees_helper::STATUS_PAID;
        }

        if (!semester_fees_helper::has_published_schedule(
            (int)$period['programmeid'],
            (int)$period['academicyearid'],
            (int)$period['semesterid']
        )) {
            return student_fees_helper::get_status($userid)['status'] === student_fees_helper::STATUS_PAID;
        }

        $row = self::get_row($userid, (int)$period['academicyearid'], (int)$period['semesterid']);
        if ($row) {
            return (string)$row->status === self::STATUS_PAID;
        }

        return false;
    }

    /**
     * Mark the student's current institutional semester paid (idempotent).
     */
    public static function mark_current_semester_paid(
        int $userid,
        string $source,
        string $reference,
        int $recordedby = 0,
        ?int $amountminor = null,
        ?string $currency = null
    ): bool {
        $period = self::resolve_current_period($userid);
        if (!$period) {
            return false;
        }
        return self::mark_semester_paid(
            $userid,
            (int)$period['programmeid'],
            (int)$period['academicyearid'],
            (int)$period['semesterid'],
            $source,
            $reference,
            $recordedby,
            $amountminor,
            $currency
        );
    }

    public static function mark_semester_paid(
        int $userid,
        int $programmeid,
        int $academicyearid,
        int $semesterid,
        string $source,
        string $reference,
        int $recordedby = 0,
        ?int $amountminor = null,
        ?string $currency = null
    ): bool {
        global $DB;

        if (!self::table_exists() || $userid <= 0 || $semesterid <= 0 || $academicyearid <= 0) {
            return false;
        }

        $now = time();
        $row = self::get_row($userid, $academicyearid, $semesterid);
        if ($amountminor === null || $currency === null) {
            $fee = semester_fees_helper::compute_student_total(
                $programmeid,
                $academicyearid,
                $semesterid
            );
            if ($amountminor === null) {
                $amountminor = (int)$fee['amount'];
            }
            if ($currency === null) {
                $currency = (string)$fee['currency'];
            }
        }

        if ($row && (string)$row->status === self::STATUS_PAID) {
            return true;
        }

        if ($row) {
            $row->status = self::STATUS_PAID;
            $row->paid_at = $now;
            $row->source = $source;
            $row->reference = trim($reference);
            $row->recordedby = $recordedby;
            $row->amount = (int)$amountminor;
            $row->currency = strtoupper((string)$currency);
            $row->timemodified = $now;
            $DB->update_record('local_btiportal_student_semester_fees', $row);
        } else {
            $DB->insert_record('local_btiportal_student_semester_fees', (object) [
                'userid' => $userid,
                'programmeid' => $programmeid,
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
                'status' => self::STATUS_PAID,
                'amount' => (int)$amountminor,
                'currency' => strtoupper((string)$currency),
                'due_at' => 0,
                'paid_at' => $now,
                'source' => $source,
                'reference' => trim($reference),
                'recordedby' => $recordedby,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $period = self::resolve_current_period($userid);
        if ($period
            && (int)$period['academicyearid'] === $academicyearid
            && (int)$period['semesterid'] === $semesterid) {
            student_fees_helper::sync_legacy_paid_flag($userid, $source, $reference, $recordedby);
        }

        return true;
    }

    /**
     * Create unpaid rows for all programme students (publish path).
     */
    public static function ensure_unpaid_for_programme_period(
        int $programmeid,
        int $academicyearid,
        int $semesterid,
        int $dueat,
        int $amountminor,
        string $currency
    ): int {
        global $DB;

        if (!self::table_exists() || $programmeid <= 0 || $semesterid <= 0) {
            return 0;
        }

        $userids = $DB->get_fieldset_select(
            'local_btiportal_student_profile',
            'userid',
            'programmeid = ? AND userid > 0',
            [$programmeid]
        );
        $now = time();
        $created = 0;
        foreach ($userids as $userid) {
            $userid = (int)$userid;
            if ($userid <= 0) {
                continue;
            }
            $existing = self::get_row($userid, $academicyearid, $semesterid);
            if ($existing) {
                if ((string)$existing->status === self::STATUS_PAID) {
                    continue;
                }
                $existing->amount = $amountminor;
                $existing->currency = strtoupper($currency);
                $existing->due_at = $dueat;
                $existing->timemodified = $now;
                $DB->update_record('local_btiportal_student_semester_fees', $existing);
                continue;
            }
            $DB->insert_record('local_btiportal_student_semester_fees', (object) [
                'userid' => $userid,
                'programmeid' => $programmeid,
                'academicyearid' => $academicyearid,
                'semesterid' => $semesterid,
                'status' => self::STATUS_UNPAID,
                'amount' => $amountminor,
                'currency' => strtoupper($currency),
                'due_at' => $dueat,
                'paid_at' => 0,
                'source' => '',
                'reference' => '',
                'recordedby' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $created++;
        }
        return $created;
    }

    /**
     * Upgrade: legacy global paid → paid for resolved current semester.
     */
    public static function migrate_legacy_paid_rows(): int {
        global $DB;

        if (!self::table_exists() || !student_fees_helper::table_exists()) {
            return 0;
        }

        $paidusers = $DB->get_records_select(
            'local_btiportal_student_fees',
            "status = ? AND userid > 0",
            [student_fees_helper::STATUS_PAID],
            '',
            'userid,source,reference,paid_at'
        );

        $count = 0;
        foreach ($paidusers as $legacy) {
            $userid = (int)$legacy->userid;
            $period = self::resolve_current_period($userid);
            if (!$period) {
                continue;
            }
            $row = self::get_row($userid, (int)$period['academicyearid'], (int)$period['semesterid']);
            if ($row && (string)$row->status === self::STATUS_PAID) {
                continue;
            }
            $fee = semester_fees_helper::compute_student_total(
                (int)$period['programmeid'],
                (int)$period['academicyearid'],
                (int)$period['semesterid']
            );
            $amount = (int)$fee['amount'];
            $currency = (string)$fee['currency'];
            if ($amount <= 0) {
                $prog = fees_payment_helper::get_programme_fee((int)$period['programmeid']);
                $amount = (int)$prog['amount'];
                $currency = (string)$prog['currency'];
            }

            if ($row) {
                $row->status = self::STATUS_PAID;
                $row->paid_at = (int)($legacy->paid_at ?: time());
                $row->source = (string)($legacy->source ?: student_fees_helper::SOURCE_MIGRATION);
                $row->reference = (string)($legacy->reference ?: 'legacy-migration');
                $row->amount = $amount;
                $row->currency = strtoupper($currency);
                $row->timemodified = time();
                $DB->update_record('local_btiportal_student_semester_fees', $row);
            } else {
                $now = time();
                $DB->insert_record('local_btiportal_student_semester_fees', (object) [
                    'userid' => $userid,
                    'programmeid' => (int)$period['programmeid'],
                    'academicyearid' => (int)$period['academicyearid'],
                    'semesterid' => (int)$period['semesterid'],
                    'status' => self::STATUS_PAID,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                    'due_at' => 0,
                    'paid_at' => (int)($legacy->paid_at ?: $now),
                    'source' => (string)($legacy->source ?: student_fees_helper::SOURCE_MIGRATION),
                    'reference' => (string)($legacy->reference ?: 'legacy-migration'),
                    'recordedby' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            $count++;
        }
        return $count;
    }

    /** @return array{status:string,paid_at:int,amount:int,currency:string,due_at:int} */
    public static function current_semester_status(int $userid): array {
        $period = self::resolve_current_period($userid);
        if (!$period || !self::table_exists()) {
            $legacy = student_fees_helper::get_status($userid);
            $progid = $period ? (int)$period['programmeid'] : fees_payment_helper::resolve_student_programme_id($userid);
            $tuition = fees_payment_helper::get_programme_fee($progid);
            return [
                'status' => $legacy['status'],
                'paid_at' => (int)$legacy['paid_at'],
                'amount' => (int)$tuition['amount'],
                'currency' => (string)$tuition['currency'],
                'due_at' => 0,
            ];
        }

        $row = self::get_row($userid, (int)$period['academicyearid'], (int)$period['semesterid']);
        $fee = semester_fees_helper::compute_student_total(
            (int)$period['programmeid'],
            (int)$period['academicyearid'],
            (int)$period['semesterid']
        );
        $published = !empty($fee['published']);
        $amount = (int)$fee['amount'];
        $currency = (string)$fee['currency'];
        $dueat = (int)$fee['due_at'];

        if (!$published) {
            $legacy = student_fees_helper::get_status($userid);
            return [
                'status' => $legacy['status'],
                'paid_at' => (int)$legacy['paid_at'],
                'amount' => 0,
                'currency' => semester_fees_helper::DEFAULT_CURRENCY,
                'due_at' => 0,
                'published' => false,
            ];
        }

        if (!$row) {
            return [
                'status' => self::STATUS_UNPAID,
                'paid_at' => 0,
                'amount' => $amount,
                'currency' => $currency,
                'due_at' => $dueat,
                'published' => true,
            ];
        }

        return [
            'status' => (string)$row->status,
            'paid_at' => (int)$row->paid_at,
            'amount' => (int)($row->amount > 0 ? $row->amount : $amount),
            'currency' => strtoupper((string)($row->currency ?: $currency)),
            'due_at' => (int)($row->due_at > 0 ? $row->due_at : $dueat),
            'published' => true,
        ];
    }

    public static function is_semester_paid(int $userid, int $academicyearid, int $semesterid): bool {
        if (!self::table_exists()) {
            return false;
        }
        $row = self::get_row($userid, $academicyearid, $semesterid);
        if ($row) {
            return (string)$row->status === self::STATUS_PAID;
        }
        return false;
    }

    /**
     * All published programme semesters with student obligation state.
     *
     * @return array{items:list,total_outstanding:int,currency:string}
     */
    public static function list_bills_for_user(int $userid): array {
        global $DB;

        $period = self::resolve_current_period($userid);
        if (!$period) {
            return ['items' => [], 'total_outstanding' => 0, 'currency' => semester_fees_helper::DEFAULT_CURRENCY];
        }

        $periods = semester_fees_helper::list_published_periods_for_programme((int)$period['programmeid']);
        $now = time();
        $items = [];
        $total = 0;
        $currency = semester_fees_helper::DEFAULT_CURRENCY;

        foreach ($periods as $p) {
            $fee = semester_fees_helper::compute_student_total(
                (int)$period['programmeid'],
                (int)$p['academicyearid'],
                (int)$p['semesterid']
            );
            $row = self::get_row($userid, (int)$p['academicyearid'], (int)$p['semesterid']);
            $paid = $row && (string)$row->status === self::STATUS_PAID;
            $dueat = (int)($row->due_at ?? $p['due_at'] ?? $fee['due_at']);
            $amount = (int)($row && $row->amount > 0 ? $row->amount : $fee['amount']);
            $cur = strtoupper((string)($row->currency ?? $fee['currency'] ?? $currency));
            $currency = $cur;

            $displaystatus = 'Unpaid';
            if ($paid) {
                $displaystatus = 'Paid';
            } else if ($dueat > 0 && $dueat < $now) {
                $displaystatus = 'Overdue';
            }

            if (!$paid) {
                $total += $amount;
            }

            $items[] = [
                'academicyearid' => (int)$p['academicyearid'],
                'semesterid' => (int)$p['semesterid'],
                'academic_year' => (string)$p['academic_year'],
                'semester' => (string)$p['semester'],
                'amount' => $amount,
                'currency' => $cur,
                'due_at' => $dueat,
                'status' => $displaystatus,
                'is_current' => (int)$period['academicyearid'] === (int)$p['academicyearid']
                    && (int)$period['semesterid'] === (int)$p['semesterid'],
            ];
        }

        return ['items' => $items, 'total_outstanding' => $total, 'currency' => $currency];
    }
}