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
            return ['amount' => 0, 'currency' => 'KES'];
        }
        $row = $DB->get_record('local_btiportal_programme', ['id' => $programmeid], 'tuitionfeeamount, tuitionfeecurrency', IGNORE_MISSING);
        if (!$row) {
            return ['amount' => 0, 'currency' => 'KES'];
        }
        return [
            'amount' => max(0, (int)$row->tuitionfeeamount),
            'currency' => strtoupper(trim((string)($row->tuitionfeecurrency ?: 'KES'))),
        ];
    }

    public static function resolve_student_programme_id(int $userid): int {
        $raw = student_profile_helper::get_by_userid($userid);
        return $raw ? (int)($raw['programmeid'] ?? 0) : 0;
    }

    /**
     * Server-side amount for the student's current semester obligation.
     *
     * @return array{amount:int,currency:string,programmeid:int,academicyearid:int,semesterid:int}
     */
    public static function online_payments_available(): bool {
        return paystack_client::secret_key() !== '';
    }

    /**
     * New admissions (no semester paid yet) may only pay the semester on their profile.
     */
    public static function is_first_time_tuition_payer(int $userid): bool {
        global $DB;

        if (student_semester_fees_helper::table_exists()) {
            $paid = $DB->count_records_select(
                'local_btiportal_student_semester_fees',
                'userid = ? AND status = ?',
                [$userid, student_semester_fees_helper::STATUS_PAID]
            );
            if ($paid > 0) {
                return false;
            }
        }
        return !student_fees_helper::is_paid($userid);
    }

    public static function may_pay_semester_online(int $userid, int $academicyearid, int $semesterid): bool {
        $period = student_semester_fees_helper::resolve_current_period($userid);
        if (!$period || $academicyearid <= 0 || $semesterid <= 0) {
            return false;
        }
        $iscurrent = (int)$period['academicyearid'] === $academicyearid
            && (int)$period['semesterid'] === $semesterid;
        if (self::is_first_time_tuition_payer($userid)) {
            return $iscurrent;
        }
        return true;
    }

    /**
     * @return array{amount:int,currency:string,programmeid:int,academicyearid:int,semesterid:int,published:bool}
     */
    public static function resolve_checkout_amount(int $userid, int $academicyearid = 0, int $semesterid = 0): array {
        $programmeid = self::resolve_student_programme_id($userid);
        $period = student_semester_fees_helper::resolve_current_period($userid);
        if ($academicyearid > 0 && $semesterid > 0) {
            if ($period && (int)$period['programmeid'] > 0) {
                $programmeid = (int)$period['programmeid'];
            }
        } else if ($period) {
            $academicyearid = (int)$period['academicyearid'];
            $semesterid = (int)$period['semesterid'];
        }

        if ($programmeid <= 0 || $academicyearid <= 0 || $semesterid <= 0) {
            return [
                'amount' => 0,
                'currency' => semester_fees_helper::DEFAULT_CURRENCY,
                'programmeid' => $programmeid,
                'academicyearid' => 0,
                'semesterid' => 0,
                'published' => false,
            ];
        }

        $fee = semester_fees_helper::compute_student_total($programmeid, $academicyearid, $semesterid);
        return [
            'amount' => (int)$fee['amount'],
            'currency' => (string)$fee['currency'],
            'programmeid' => $programmeid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'published' => !empty($fee['published']),
        ];
    }

    private static function semester_already_paid(int $userid, int $academicyearid, int $semesterid): bool {
        if ($academicyearid > 0 && $semesterid > 0) {
            return student_semester_fees_helper::is_semester_paid($userid, $academicyearid, $semesterid);
        }
        return student_fees_helper::is_paid($userid);
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
    public static function initialize_for_user(
        int $userid,
        bool $savecard = false,
        int $academicyearid = 0,
        int $semesterid = 0
    ): array {
        global $DB;

        fees_student_auth_helper::require_eligible_student_payer($userid);

        if (!self::attempts_table_exists()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Payment module not installed']]];
        }
        if (!self::online_payments_available()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Online payment is not configured']]];
        }

        $checkout = self::resolve_checkout_amount($userid, $academicyearid, $semesterid);
        $programmeid = (int)$checkout['programmeid'];
        if ($programmeid <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'programme', 'message' => 'No programme on student profile']]];
        }
        if (empty($checkout['published']) || $checkout['amount'] <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'amount', 'message' => 'No fee published for this semester']]];
        }
        if (self::semester_already_paid($userid, (int)$checkout['academicyearid'], (int)$checkout['semesterid'])) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Fees already paid for this semester']]];
        }
        if (!self::may_pay_semester_online($userid, (int)$checkout['academicyearid'], (int)$checkout['semesterid'])) {
            return ['ok' => false, 'errors' => [[
                'field' => '',
                'message' => 'New students can only pay the current semester online. Message admin for other balances.',
            ]]];
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $reference = self::make_reference($userid);
        $now = time();

        $feecurrency = (string) $checkout['currency'];
        $paycurrency = paystack_client::currency_for_payment($feecurrency);
        if ($paycurrency === '') {
            return ['ok' => false, 'errors' => [[
                'field' => 'currency',
                'message' => 'No valid currency for Paystack. Set Paystack collection currency (KES) in BTI Portal settings.',
            ]]];
        }

        $record = (object) [
            'reference' => $reference,
            'userid' => $userid,
            'programmeid' => $programmeid,
            'amount' => (int)$checkout['amount'],
            'currency' => $paycurrency,
            'status' => self::STATUS_PENDING,
            'paystackref' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        if (self::attempt_has_semester_fields()) {
            $record->academicyearid = (int)$checkout['academicyearid'];
            $record->semesterid = (int)$checkout['semesterid'];
        }
        if (self::attempt_has_savecard_field()) {
            $record->savecard = $savecard ? 1 : 0;
        }
        $DB->insert_record('local_btiportal_fees_payment_attempt', $record);

        $init = paystack_client::initialize_transaction(
            (string)$user->email,
            (int)$checkout['amount'],
            $paycurrency,
            $reference,
            self::portal_return_url($reference),
            [
                'userid' => $userid,
                'programmeid' => $programmeid,
                'academicyearid' => (int)$checkout['academicyearid'],
                'semesterid' => (int)$checkout['semesterid'],
                'save_card' => $savecard ? 1 : 0,
                'fee_currency' => $feecurrency,
            ]
        );

        if (!$init['ok']) {
            self::mark_attempt($reference, self::STATUS_FAILED);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $init['message'] ?? 'Paystack initialize failed']]];
        }

        $data = $init['data'] ?? [];
        if (isset($data['reference']) && (string)$data['reference'] !== $reference) {
            $DB->set_field('local_btiportal_fees_payment_attempt', 'paystackref', (string)$data['reference'], ['reference' => $reference]);
        }

        $out = [
            'ok' => true,
            'reference' => $reference,
            'authorization_url' => (string)($data['authorization_url'] ?? ''),
            'access_code' => (string)($data['access_code'] ?? ''),
            'amount' => (int)$checkout['amount'],
            'currency' => $paycurrency,
        ];
        $public = paystack_client::public_key();
        if ($public !== '' && preg_match('/^pk_(test|live)_/', $public)) {
            $out['paystack_public_key'] = $public;
        }
        return $out;
    }

    /**
     * Charge a saved Paystack authorization for the current semester total.
     *
     * @return array{ok:bool,reference?:string,paid?:bool,errors?:list}
     */
    public static function pay_with_saved_card(
        int $userid,
        int $cardid,
        int $academicyearid = 0,
        int $semesterid = 0
    ): array {
        global $DB;

        fees_student_auth_helper::require_eligible_student_payer($userid);
        if (!self::online_payments_available()) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Online payment is not configured']]];
        }
        $card = student_card_helper::get_owned($cardid, $userid);
        if (!$card || empty($card->reusable)) {
            return ['ok' => false, 'errors' => [['field' => 'cardid', 'message' => 'Card not found or not reusable']]];
        }

        $checkout = self::resolve_checkout_amount($userid, $academicyearid, $semesterid);
        if (empty($checkout['published']) || $checkout['amount'] <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'amount', 'message' => 'No fee published for this semester']]];
        }
        if (self::semester_already_paid($userid, (int)$checkout['academicyearid'], (int)$checkout['semesterid'])) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Fees already paid for this semester']]];
        }
        if (!self::may_pay_semester_online($userid, (int)$checkout['academicyearid'], (int)$checkout['semesterid'])) {
            return ['ok' => false, 'errors' => [[
                'field' => '',
                'message' => 'New students can only pay the current semester online. Message admin for other balances.',
            ]]];
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $reference = self::make_reference($userid);
        $now = time();
        $feecurrency = (string) $checkout['currency'];
        $paycurrency = paystack_client::currency_for_payment($feecurrency);
        if ($paycurrency === '') {
            return ['ok' => false, 'errors' => [[
                'field' => 'currency',
                'message' => 'No valid currency for Paystack. Set Paystack collection currency (KES) in BTI Portal settings.',
            ]]];
        }

        $record = (object) [
            'reference' => $reference,
            'userid' => $userid,
            'programmeid' => (int)$checkout['programmeid'],
            'amount' => (int)$checkout['amount'],
            'currency' => $paycurrency,
            'status' => self::STATUS_PENDING,
            'paystackref' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        if (self::attempt_has_semester_fields()) {
            $record->academicyearid = (int)$checkout['academicyearid'];
            $record->semesterid = (int)$checkout['semesterid'];
        }
        if (self::attempt_has_savecard_field()) {
            $record->savecard = 0;
        }
        $DB->insert_record('local_btiportal_fees_payment_attempt', $record);

        $charge = paystack_client::charge_authorization(
            (string)$user->email,
            (int)$checkout['amount'],
            $paycurrency,
            (string)$card->authorization_code,
            $reference
        );

        if (!$charge['ok']) {
            self::mark_attempt($reference, self::STATUS_FAILED);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $charge['message'] ?? 'Charge failed']]];
        }

        $data = $charge['data'] ?? [];
        $done = self::complete_from_verified_paystack_data($reference, $data, true);
        if (!$done['ok']) {
            return $done;
        }
        return [
            'ok' => true,
            'reference' => $reference,
            'paid' => !empty($done['paid']),
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
            $paid = self::semester_paid_for_attempt($attempt);
            return ['ok' => true, 'verified' => true, 'paid' => $paid];
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

        $savecard = self::attempt_has_savecard_field() && !empty($attempt->savecard);
        student_card_helper::maybe_store_from_transaction((int)$attempt->userid, $data, $savecard);

        $complete = self::complete_success($reference, (int)$attempt->userid);
        if (!$complete['ok']) {
            return $complete;
        }
        return [
            'ok' => true,
            'verified' => true,
            'paid' => self::semester_paid_for_attempt($attempt),
        ];
    }

    private static function semester_paid_for_attempt(\stdClass $attempt): bool {
        $userid = (int)$attempt->userid;
        $ay = (int)($attempt->academicyearid ?? 0);
        $sem = (int)($attempt->semesterid ?? 0);
        if ($ay > 0 && $sem > 0) {
            return student_semester_fees_helper::is_semester_paid($userid, $ay, $sem);
        }
        return student_fees_helper::is_paid($userid);
    }

    /**
     * Webhook / verified transaction — idempotent on reference.
     *
     * @return array{ok:bool,accepted?:bool,already?:bool,errors?:list}
     */
    public static function complete_success(string $reference, int $expecteduserid = 0): array {
        $attempt = self::get_attempt($reference);
        if (!$attempt) {
            return ['ok' => false, 'errors' => [['field' => 'reference', 'message' => 'Unknown payment reference']]];
        }
        if ($expecteduserid > 0 && (int)$attempt->userid !== $expecteduserid) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Reference does not belong to this user']]];
        }

        if ($attempt->status === self::STATUS_SUCCESS) {
            student_fees_helper::mark_paid_for_payment_attempt(
                $attempt,
                student_fees_helper::SOURCE_PAYSTACK,
                $reference,
                0
            );
            return ['ok' => true, 'accepted' => true, 'already' => true];
        }

        $mark = student_fees_helper::mark_paid_for_payment_attempt(
            $attempt,
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

    /**
     * @return array{status:string,amount:int,currency:string,due_at:int,lines:list,cards:list,default_card_id:int|null}
     */
    public static function checkout_context_for_user(int $userid): array {
        $semester = student_semester_fees_helper::current_semester_status($userid);
        $period = student_semester_fees_helper::resolve_current_period($userid);
        $lines = [];
        if ($period) {
            $lines = semester_fees_helper::list_lines(
                (int)$period['programmeid'],
                (int)$period['academicyearid'],
                (int)$period['semesterid'],
                true
            );
        }
        $cards = student_card_helper::list_for_user($userid);
        $defaultid = null;
        foreach ($cards as $c) {
            if (!empty($c['is_default'])) {
                $defaultid = (int)$c['id'];
                break;
            }
        }
        if ($defaultid === null && $cards) {
            $defaultid = (int)$cards[0]['id'];
        }

        $bills = student_semester_fees_helper::list_bills_for_user($userid);
        $online = self::online_payments_available();
        $firsttime = self::is_first_time_tuition_payer($userid);
        foreach ($bills['items'] as &$bill) {
            $maypay = self::may_pay_semester_online(
                $userid,
                (int)$bill['academicyearid'],
                (int)$bill['semesterid']
            );
            $bill['can_pay_online'] = $online
                && $bill['status'] !== 'Paid'
                && (int)$bill['amount'] > 0
                && $maypay;
            if ($bill['status'] === 'Paid') {
                $bill['pay_disabled_reason'] = '';
            } else if ($bill['amount'] <= 0) {
                $bill['pay_disabled_reason'] = 'No fee published yet';
            } else if (!$online) {
                $bill['pay_disabled_reason'] = paystack_client::payment_unavailable_message();
            } else if (!$maypay && $firsttime && empty($bill['is_current'])) {
                $bill['pay_disabled_reason'] =
                    'As a new student, pay only your current semester online. Message admin for other semesters.';
            } else if (!$maypay) {
                $bill['pay_disabled_reason'] = 'This semester cannot be paid online.';
            } else {
                $bill['pay_disabled_reason'] = '';
            }
        }
        unset($bill);

        return [
            'status' => (string)$semester['status'],
            'amount' => (int)$semester['amount'],
            'currency' => (string)$semester['currency'],
            'due_at' => (int)$semester['due_at'],
            'published' => !empty($semester['published']),
            'lines' => $lines,
            'cards' => $cards,
            'default_card_id' => $defaultid,
            'bills' => $bills['items'],
            'total_outstanding' => (int)$bills['total_outstanding'],
            'online_payments_available' => $online,
            'paystack_public_key' => paystack_client::public_key(),
            'paystack_inline_ready' => paystack_client::inline_checkout_ready(),
            'first_time_payer' => $firsttime,
            'current_period' => $period ? [
                'academicyearid' => (int)$period['academicyearid'],
                'semesterid' => (int)$period['semesterid'],
            ] : null,
        ];
    }

    private static function attempt_has_semester_fields(): bool {
        global $DB;
        if (!self::attempts_table_exists()) {
            return false;
        }
        return $DB->get_manager()->field_exists('local_btiportal_fees_payment_attempt', 'semesterid');
    }

    private static function attempt_has_savecard_field(): bool {
        global $DB;
        if (!self::attempts_table_exists()) {
            return false;
        }
        return $DB->get_manager()->field_exists('local_btiportal_fees_payment_attempt', 'savecard');
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