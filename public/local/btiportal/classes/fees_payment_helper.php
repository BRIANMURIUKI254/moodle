<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Tuition payment attempts + Paystack orchestration.
 */
class fees_payment_helper {
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ABANDONED = 'abandoned';

    public static function attempts_table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('local_btiportal_fees_payment_attempt');
    }

    public static function get_programme_fee(int $programmeid): array {
        global $DB;
        if ($programmeid <= 0) {
            return ['amount' => 0, 'currency' => 'NGN'];
        }
        $row = $DB->get_record('local_btiportal_programme', ['id' => $programmeid], 'tuitionfeeamount, tuitionfeecurrency', IGNORE_MISSING);
        if (!$row) {
            return ['amount' => 0, 'currency' => 'NGN'];
        }
        return [
            'amount' => max(0, (int)$row->tuitionfeeamount),
            'currency' => strtoupper(trim((string)($row->tuitionfeecurrency ?: 'NGN'))),
        ];
    }

    public static function resolve_student_programme_id(int $userid): int {
        $raw = student_profile_helper::get_by_userid($userid);
        return $raw ? (int)($raw['programmeid'] ?? 0) : 0;
    }

    public static function portal_return_url(string $reference): string {
        $base = trim((string) get_config('local_btiportal', 'portalurl'));
        if ($base === '') {
            $base = 'http://localhost:8080';
        }
        $base = rtrim($base, '/');
        return $base . '/fees?reference=' . rawurlencode($reference);
    }

    public static function make_reference(int $userid): string {
        return 'BTI-FEE-' . $userid . '-' . time() . '-' . random_int(1000, 9999);
    }

    /**
     * @return array{ok:bool,reference?:string,authorization_url?:string,amount?:int,currency?:string,errors?:list}
     */
    public static function initialize_for_user(int $userid): array {
        global $DB;

        fees_student_auth_helper::require_eligible_student_payer($userid);

        if (!self::attempts_table_exists()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Payment module not installed']]];
        }
        if (student_fees_helper::is_paid($userid)) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Fees already paid']]];
        }

        $programmeid = self::resolve_student_programme_id($userid);
        if ($programmeid <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'programme', 'message' => 'No programme on student profile']]];
        }

        $fee = self::get_programme_fee($programmeid);
        if ($fee['amount'] <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'amount', 'message' => 'Tuition fee is not configured for this programme']]];
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $reference = self::make_reference($userid);
        $now = time();

        $DB->insert_record('local_btiportal_fees_payment_attempt', (object) [
            'reference' => $reference,
            'userid' => $userid,
            'programmeid' => $programmeid,
            'amount' => $fee['amount'],
            'currency' => $fee['currency'],
            'status' => self::STATUS_PENDING,
            'paystackref' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $init = paystack_client::initialize_transaction(
            (string)$user->email,
            $fee['amount'],
            $fee['currency'],
            $reference,
            self::portal_return_url($reference),
            ['userid' => $userid, 'programmeid' => $programmeid]
        );

        if (!$init['ok']) {
            self::mark_attempt($reference, self::STATUS_FAILED);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $init['message'] ?? 'Paystack initialize failed']]];
        }

        $data = $init['data'] ?? [];
        if (isset($data['reference']) && (string)$data['reference'] !== $reference) {
            $DB->set_field('local_btiportal_fees_payment_attempt', 'paystackref', (string)$data['reference'], ['reference' => $reference]);
        }

        return [
            'ok' => true,
            'reference' => $reference,
            'authorization_url' => (string)($data['authorization_url'] ?? ''),
            'amount' => $fee['amount'],
            'currency' => $fee['currency'],
        ];
    }

    /**
     * Verify with Paystack and complete if successful (idempotent).
     *
     * @return array{ok:bool,verified?:bool,paid?:bool,errors?:list}
     */
    public static function verify_and_complete(string $reference, bool $strictamount = true): array {
        $attempt = self::get_attempt($reference);
        if (!$attempt) {
            return ['ok' => false, 'errors' => [['field' => 'reference', 'message' => 'Unknown payment reference']]];
        }

        if ($attempt->status === self::STATUS_SUCCESS) {
            return ['ok' => true, 'verified' => true, 'paid' => student_fees_helper::is_paid((int)$attempt->userid)];
        }

        $verify = paystack_client::verify_transaction($reference);
        if (!$verify['ok']) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $verify['message'] ?? 'Verification failed']]];
        }

        return self::complete_from_verified_paystack_data($reference, $verify['data'] ?? [], $strictamount);
    }

    /**
     * Apply Paystack verify payload to an attempt (webhook + return-verify path).
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,verified?:bool,paid?:bool,errors?:list}
     */
    public static function complete_from_verified_paystack_data(string $reference, array $data, bool $strictamount = true): array {
        $attempt = self::get_attempt($reference);
        if (!$attempt) {
            return ['ok' => false, 'errors' => [['field' => 'reference', 'message' => 'Unknown payment reference']]];
        }

        $status = strtolower((string)($data['status'] ?? ''));
        if ($status !== 'success') {
            return ['ok' => true, 'verified' => false, 'paid' => false];
        }

        $amount = (int)($data['amount'] ?? 0);
        $currency = strtoupper((string)($data['currency'] ?? ''));
        if ($strictamount) {
            if ($amount !== (int)$attempt->amount || $currency !== strtoupper((string)$attempt->currency)) {
                return ['ok' => false, 'errors' => [['field' => 'amount', 'message' => 'Paystack amount/currency does not match attempt']]];
            }
        }

        $complete = self::complete_success($reference, (int)$attempt->userid);
        if (!$complete['ok']) {
            return $complete;
        }
        return [
            'ok' => true,
            'verified' => true,
            'paid' => student_fees_helper::is_paid((int)$attempt->userid),
        ];
    }

    /**
     * Webhook / verified transaction — idempotent on reference.
     *
     * @return array{ok:bool,accepted?:bool,already?:bool,errors?:list}
     */
    public static function complete_success(string $reference, int $expecteduserid = 0): array {
        global $DB;

        $attempt = self::get_attempt($reference);
        if (!$attempt) {
            return ['ok' => false, 'errors' => [['field' => 'reference', 'message' => 'Unknown payment reference']]];
        }
        if ($expecteduserid > 0 && (int)$attempt->userid !== $expecteduserid) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Reference does not belong to this user']]];
        }

        if ($attempt->status === self::STATUS_SUCCESS) {
            student_fees_helper::mark_paid_and_enrol((int)$attempt->userid, student_fees_helper::SOURCE_PAYSTACK, $reference, 0);
            return ['ok' => true, 'accepted' => true, 'already' => true];
        }

        $mark = student_fees_helper::mark_paid_and_enrol(
            (int)$attempt->userid,
            student_fees_helper::SOURCE_PAYSTACK,
            $reference,
            0
        );
        if (!$mark['ok']) {
            return ['ok' => false, 'errors' => $mark['errors'] ?? []];
        }

        self::mark_attempt($reference, self::STATUS_SUCCESS);

        return ['ok' => true, 'accepted' => true, 'already' => false];
    }

    public static function process_webhook_payload(string $rawbody, ?string $signature): array {
        if (!paystack_client::verify_webhook_signature($rawbody, $signature)) {
            return ['ok' => false, 'http' => 401, 'message' => 'Invalid signature'];
        }

        $payload = json_decode($rawbody, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'http' => 400, 'message' => 'Invalid JSON'];
        }

        $event = (string)($payload['event'] ?? '');
        if ($event !== 'charge.success') {
            return ['ok' => true, 'http' => 200, 'message' => 'Ignored event'];
        }

        $reference = (string)($payload['data']['reference'] ?? '');
        if ($reference === '') {
            return ['ok' => false, 'http' => 400, 'message' => 'Missing reference'];
        }

        $result = self::verify_and_complete($reference, true);
        if (!$result['ok']) {
            return ['ok' => false, 'http' => 400, 'message' => $result['errors'][0]['message'] ?? 'Rejected'];
        }

        return ['ok' => true, 'http' => 200, 'message' => 'OK'];
    }

    private static function get_attempt(string $reference): ?\stdClass {
        global $DB;
        if (!self::attempts_table_exists()) {
            return null;
        }
        return $DB->get_record('local_btiportal_fees_payment_attempt', ['reference' => $reference]) ?: null;
    }

    private static function mark_attempt(string $reference, string $status): void {
        global $DB;
        $DB->set_field('local_btiportal_fees_payment_attempt', 'status', $status, ['reference' => $reference]);
        $DB->set_field('local_btiportal_fees_payment_attempt', 'timemodified', time(), ['reference' => $reference]);
    }
}