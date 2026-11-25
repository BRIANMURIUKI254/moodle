<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal Paystack REST client (server-side only).
 */
class paystack_client {
    private const API_BASE = 'https://api.paystack.co';

    /** Currencies Paystack accepts on transaction initialize (merchant must be enabled for code). */
    private const SUPPORTED_CURRENCIES = ['NGN', 'USD', 'GHS', 'ZAR', 'KES', 'XOF'];

    public static function secret_key(): string {
        $key = trim((string) get_config('local_btiportal', 'paystack_secret_key'));
        if ($key === '') {
            $env = getenv('BTI_PAYSTACK_SECRET_KEY');
            if ($env !== false && trim($env) !== '') {
                $key = trim($env);
            }
        }
        if ($key === '') {
            $key = self::enrol_paystack_key('secret');
        }
        return $key;
    }

    public static function public_key(): string {
        $key = trim((string) get_config('local_btiportal', 'paystack_public_key'));
        if ($key === '') {
            $env = getenv('BTI_PAYSTACK_PUBLIC_KEY');
            if ($env !== false && trim($env) !== '') {
                $key = trim($env);
            }
        }
        if ($key === '') {
            $key = self::enrol_paystack_key('public');
        }
        return $key;
    }

    /**
     * Reuse Paystack API keys from enrol_paystack when BTI Portal keys are blank.
     *
     * @param 'secret'|'public' $kind
     */
    private static function enrol_paystack_key(string $kind): string {
        $mode = strtolower(trim((string) get_config('enrol_paystack', 'mode')));
        $live = $mode === 'live' || $mode === '1';
        if ($kind === 'secret') {
            if ($live) {
                return trim((string) get_config('enrol_paystack', 'live_secretkey'));
            }
            $test = trim((string) get_config('enrol_paystack', 'test_secretkey'));
            return $test !== '' ? $test : trim((string) get_config('enrol_paystack', 'live_secretkey'));
        }
        if ($live) {
            return trim((string) get_config('enrol_paystack', 'live_publickey'));
        }
        $test = trim((string) get_config('enrol_paystack', 'test_publickey'));
        return $test !== '' ? $test : trim((string) get_config('enrol_paystack', 'live_publickey'));
    }

    /** Card popup in the portal needs the public key; redirect checkout needs only the secret. */
    public static function inline_checkout_ready(): bool {
        return self::secret_key() !== '' && self::public_key() !== '';
    }

  /** @return array{ok:bool,data?:array,message?:string,httpcode?:int} */
    public static function initialize_transaction(
        string $email,
        int $amountminor,
        string $currency,
        string $reference,
        string $callbackurl,
        array $metadata = []
    ): array {
        $secret = self::secret_key();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Paystack secret key is not configured'];
        }
        if ($amountminor <= 0) {
            return ['ok' => false, 'message' => 'Invalid fee amount'];
        }
        $code = self::normalize_currency($currency);
        if ($code === '') {
            return ['ok' => false, 'message' => 'Currency is missing or not supported for Paystack'];
        }

        $payload = [
            'email' => $email,
            'amount' => $amountminor,
            'currency' => $code,
            'reference' => $reference,
            'callback_url' => $callbackurl,
            'metadata' => $metadata,
        ];

        try {
            return self::request('POST', '/transaction/initialize', $payload, $secret);
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok:bool,data?:array,message?:string,httpcode?:int} */
    public static function charge_authorization(
        string $email,
        int $amountminor,
        string $currency,
        string $authorizationcode,
        string $reference
    ): array {
        $secret = self::secret_key();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Paystack secret key is not configured'];
        }
        if ($amountminor <= 0 || trim($authorizationcode) === '') {
            return ['ok' => false, 'message' => 'Invalid charge request'];
        }
        $code = self::normalize_currency($currency);
        if ($code === '') {
            return ['ok' => false, 'message' => 'Currency is missing or not supported for Paystack'];
        }

        $payload = [
            'email' => $email,
            'amount' => $amountminor,
            'currency' => $code,
            'authorization_code' => $authorizationcode,
            'reference' => $reference,
        ];

        try {
            return self::request('POST', '/transaction/charge_authorization', $payload, $secret);
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok:bool,data?:array,message?:string,httpcode?:int} */
    public static function verify_transaction(string $reference): array {
        $secret = self::secret_key();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Paystack secret key is not configured'];
        }
        $ref = rawurlencode($reference);
        try {
            return self::request('GET', '/transaction/verify/' . $ref, null, $secret);
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private static function normalize_currency(string $currency): string {
        $code = strtoupper(trim($currency));
        if ($code === '' || !in_array($code, self::SUPPORTED_CURRENCIES, true)) {
            return '';
        }
        return $code;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,data?:array,message?:string,httpcode?:int}
     */
    private static function request(string $method, string $path, ?array $body, string $secret): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $url = self::API_BASE . $path;
        $curl = new \curl();
        $headers = [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
        ];
        $options = [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 30,
        ];

        if ($method === 'POST' && $body !== null) {
            $response = $curl->post($url, json_encode($body), $options);
        } else {
            $response = $curl->get($url, [], $options);
        }
        $errno = (int) $curl->get_errno();
        if ($response === false || $errno !== 0) {
            $errtext = trim((string) $curl->error);
            throw new \moodle_exception(
                'paystackhttpfailed',
                'local_btiportal',
                '',
                null,
                $errtext !== '' ? $errtext : 'Could not reach Paystack'
            );
        }
        $info = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Invalid Paystack response', 'httpcode' => $httpcode];
        }
        if (empty($decoded['status'])) {
            return [
                'ok' => false,
                'message' => (string)($decoded['message'] ?? 'Paystack request failed'),
                'httpcode' => $httpcode,
                'data' => $decoded,
            ];
        }
        return ['ok' => true, 'data' => $decoded['data'] ?? $decoded, 'httpcode' => $httpcode];
    }

    public static function verify_webhook_signature(string $rawbody, ?string $signatureheader): bool {
        $secret = self::secret_key();
        if ($secret === '' || $signatureheader === null || $signatureheader === '') {
            return false;
        }
        $computed = hash_hmac('sha512', $rawbody, $secret);
        return hash_equals($computed, $signatureheader);
    }
}