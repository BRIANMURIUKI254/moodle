<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-student tuition fees status for the portal fees gate.
 * No row in {local_btiportal_student_fees} is treated as unpaid.
 */
class student_fees_helper {
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PAID = 'paid';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_PAYSTACK = 'paystack';
    public const SOURCE_MIGRATION = 'migration';

    public static function table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('local_btiportal_student_fees');
    }

    public static function get_record(int $userid): ?\stdClass {
        global $DB;
        if ($userid <= 0 || !self::table_exists()) {
            return null;
        }
        return $DB->get_record('local_btiportal_student_fees', ['userid' => $userid]) ?: null;
    }

    /** @return array{status:string,paid_at:int,source:string,reference:string} */
    public static function get_status(int $userid): array {
        $row = self::get_record($userid);
        if (!$row) {
            return [
                'status' => self::STATUS_UNPAID,
                'paid_at' => 0,
                'source' => '',
                'reference' => '',
            ];
        }
        return [
            'status' => (string)$row->status,
            'paid_at' => (int)$row->paid_at,
            'source' => (string)($row->source ?? ''),
            'reference' => (string)($row->reference ?? ''),
        ];
    }

    public static function is_paid(int $userid): bool {
        return self::get_status($userid)['status'] === self::STATUS_PAID;
    }

    /**
     * Amount reported to the portal (minor currency units). Unpaid → 0; paid → programme tuition or last success attempt.
     */
    public static function get_amount_paid_minor(int $userid): array {
        global $DB;

        $status = self::get_status($userid);
        $currency = 'KES';
        if ($status['status'] !== self::STATUS_PAID) {
            return ['amount_paid' => 0, 'currency' => $currency];
        }

        $programmeid = 0;
        $profile = student_profile_helper::get_by_userid($userid);
        if ($profile) {
            $programmeid = (int)($profile['programmeid'] ?? 0);
        }
        $fee = fees_payment_helper::get_programme_fee($programmeid);
        $currency = $fee['currency'] ?: $currency;
        $amount = (int)$fee['amount'];

        if (fees_payment_helper::attempts_table_exists()) {
            $attempt = $DB->get_record_sql(
                "SELECT amount, currency FROM {local_btiportal_fees_payment_attempt}
                  WHERE userid = ? AND status = ? ORDER BY timemodified DESC",
                [$userid, fees_payment_helper::STATUS_SUCCESS],
                IGNORE_MISSING
            );
            if ($attempt) {
                $attemptcurrency = strtoupper((string)($attempt->currency ?: ''));
                if ($attemptcurrency === '' || $attemptcurrency === strtoupper($currency)) {
                    $amount = (int)$attempt->amount;
                    $currency = $attemptcurrency ?: $currency;
                }
            }
        }

        return ['amount_paid' => max(0, $amount), 'currency' => strtoupper($currency)];
    }

    /** Profile row required before marking paid (shared manual / Paystack / webhook path). */
    public static function require_student_profile_for_paid(int $userid): void {
        if (!student_profile_helper::get_by_userid($userid)) {
            throw new \moodle_exception('feespaymentnoprofile', 'local_btiportal');
        }
    }

    /**
     * Ensure a fees row exists (unpaid) when a student is registered via CMS/admin upsert.
     */
    public static function ensure_unpaid_row(int $userid, int $actorid = 0): void {
        global $DB;
        if ($userid <= 0 || !self::table_exists()) {
            return;
        }
        if (self::get_record($userid)) {
            return;
        }
        $now = time();
        $DB->insert_record('local_btiportal_student_fees', (object) [
            'userid' => $userid,
            'status' => self::STATUS_UNPAID,
            'paid_at' => 0,
            'source' => '',
            'reference' => '',
            'recordedby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Mark paid and enrol in the programme container course (idempotent).
     *
     * @return array{ok:bool,paid:bool,enrolled:int,reused:int,errors?:list}
     */
    public static function mark_paid_and_enrol(
        int $userid,
        string $source = self::SOURCE_MANUAL,
        string $reference = '',
        int $recordedby = 0
    ): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'paid' => false, 'enrolled' => 0, 'reused' => 0, 'errors' => [[
                'field' => 'userid',
                'message' => 'User not found',
            ]]];
        }

        try {
            self::require_student_profile_for_paid($userid);
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'paid' => false, 'enrolled' => 0, 'reused' => 0, 'errors' => [[
                'field' => 'profile',
                'message' => $e->getMessage(),
            ]]];
        }

        $now = time();
        $source = strtolower(trim($source));
        if (!in_array($source, [self::SOURCE_MANUAL, self::SOURCE_PAYSTACK, self::SOURCE_MIGRATION], true)) {
            $source = self::SOURCE_MANUAL;
        }

        $row = self::get_record($userid);
        if ($row && $row->status === self::STATUS_PAID) {
            $enrol = self::enrol_programme_container($userid);
            return [
                'ok' => true,
                'paid' => false,
                'enrolled' => (int)($enrol['enrolled'] ?? 0),
                'reused' => (int)($enrol['reused'] ?? 0),
            ];
        }

        if ($row) {
            $row->status = self::STATUS_PAID;
            $row->paid_at = $now;
            $row->source = $source;
            $row->reference = trim($reference);
            $row->recordedby = $recordedby;
            $row->timemodified = $now;
            $DB->update_record('local_btiportal_student_fees', $row);
        } else if (self::table_exists()) {
            $DB->insert_record('local_btiportal_student_fees', (object) [
                'userid' => $userid,
                'status' => self::STATUS_PAID,
                'paid_at' => $now,
                'source' => $source,
                'reference' => trim($reference),
                'recordedby' => $recordedby,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $enrol = self::enrol_programme_container($userid);
        admissions_fees_callback_helper::notify_fees_paid($userid, $source, $reference);

        return [
            'ok' => true,
            'paid' => true,
            'enrolled' => (int)($enrol['enrolled'] ?? 0),
            'reused' => (int)($enrol['reused'] ?? 0),
        ];
    }

    /**
     * @return array{enrolled:int,reused:int,courseid:int}
     */
    private static function enrol_programme_container(int $userid): array {
        global $DB;

        $profile = student_profile_helper::get_by_userid($userid);
        $programmeid = (int)($profile['programmeid'] ?? 0);
        if ($programmeid <= 0) {
            return ['enrolled' => 0, 'reused' => 0, 'courseid' => 0];
        }
        $courseid = programme_container_helper::get_container_courseid($programmeid);
        if ($courseid <= 1) {
            return ['enrolled' => 0, 'reused' => 0, 'courseid' => 0];
        }
        $result = enrolment_transition_helper::ensure_student_enrolment($userid, $courseid);
        return [
            'enrolled' => (int)($result['enrolled'] ?? 0),
            'reused' => (int)($result['reused'] ?? 0),
            'courseid' => $courseid,
        ];
    }

    /**
     * Upgrade backfill: existing students with profile and/or enrolments → paid (migration).
     */
    /**
     * Migration backfill: active (non-suspended) users with a profile row or live student enrolment.
     * Pending applicants (suspended, no enrolment) must remain unpaid.
     */
    public static function eligible_for_migration_backfill(int $userid): bool {
        global $DB;

        if ($userid <= 0) {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, suspended', IGNORE_MISSING);
        if (!$user || (int)$user->suspended === 1) {
            return false;
        }

        if ($DB->record_exists('local_btiportal_student_profile', ['userid' => $userid])) {
            return true;
        }

        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
                  JOIN {role_assignments} ra ON ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                 WHERE ue.userid = ? AND ue.status = 0";
        return $DB->record_exists_sql($sql, [$userid]);
    }

    public static function backfill_migration_paid(): int {
        global $DB;

        if (!self::table_exists()) {
            return 0;
        }

        $userids = [];
        $profiles = $DB->get_fieldset_select('local_btiportal_student_profile', 'DISTINCT userid', 'userid > 0');
        foreach ($profiles as $id) {
            $userids[(int)$id] = true;
        }

        $sql = "SELECT DISTINCT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {role_assignments} ra ON ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE r.shortname = 'student' AND ue.status = 0 AND e.status = 0";
        foreach ($DB->get_fieldset_sql($sql) as $id) {
            $userids[(int)$id] = true;
        }

        $now = time();
        $count = 0;
        foreach (array_keys($userids) as $userid) {
            if ($userid <= 0 || !self::eligible_for_migration_backfill($userid)) {
                continue;
            }
            $row = self::get_record($userid);
            if ($row && $row->status === self::STATUS_PAID) {
                continue;
            }
            if ($row) {
                $row->status = self::STATUS_PAID;
                $row->paid_at = $row->paid_at > 0 ? (int)$row->paid_at : $now;
                $row->source = self::SOURCE_MIGRATION;
                $row->reference = $row->reference ?: 'upgrade-backfill';
                $row->timemodified = $now;
                $DB->update_record('local_btiportal_student_fees', $row);
            } else {
                $DB->insert_record('local_btiportal_student_fees', (object) [
                    'userid' => $userid,
                    'status' => self::STATUS_PAID,
                    'paid_at' => $now,
                    'source' => self::SOURCE_MIGRATION,
                    'reference' => 'upgrade-backfill',
                    'recordedby' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            $count++;
        }
        return $count;
    }
}