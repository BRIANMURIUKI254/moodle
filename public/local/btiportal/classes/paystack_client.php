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

    /** @var array{secret:string,public:string,source:string,paired:bool}|null */
    private static $resolvedkeys = null;

    public static function secret_key(): string {
        return self::resolve_keys()['secret'];
    }

    public static function public_key(): string {
        return self::resolve_keys()['public'];
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
                $key = self::first_valid_secret(self::enrol_config_candidates('live', 'secret'));
                if ($key !== '') {
                    return $key;
                }
            }
            $test = self::first_valid_secret(self::enrol_config_candidates('test', 'secret'));
            if ($test !== '') {
                return $test;
            }
            return self::first_valid_secret(self::enrol_config_candidates('live', 'secret'));
        }
        if ($live) {
            $key = self::first_valid_public(self::enrol_config_candidates('live', 'public'));
            if ($key !== '') {
                return $key;
            }
        }
        $test = self::first_valid_public(self::enrol_config_candidates('test', 'public'));
        if ($test !== '') {
            return $test;
        }
        return self::first_valid_public(self::enrol_config_candidates('live', 'public'));
    }

    /**
     * @return list<string>
     */
    private static function enrol_config_candidates(string $tier, string $kind): array {
        $plugin = 'enrol_paystack';
        if ($kind === 'secret') {
            if ($tier === 'test') {
                return ['test_secretkey', 'test_secret_key', 'secretkey_test', 'testsecretkey'];
            }
            return ['live_secretkey', 'live_secret_key', 'secretkey_live', 'secretkey'];
        }
        if ($tier === 'test') {
            return ['test_publickey', 'test_public_key', 'publickey_test', 'testpublickey'];
        }
        return ['live_publickey', 'live_public_key', 'publickey_live', 'publickey'];
    }

    /**
     * @param list<string> $names
     */
    private static function first_valid_secret(array $names): string {
        foreach ($names as $name) {
            $raw = self::normalize_stored_key((string) get_config('enrol_paystack', $name));
            if (self::is_valid_secret($raw)) {
                return $raw;
            }
        }
        return '';
    }

    /**
     * @param list<string> $names
     */
    private static function first_valid_public(array $names): string {
        foreach ($names as $name) {
            $raw = self::normalize_stored_key((string) get_config('enrol_paystack', $name));
            if (self::is_valid_public($raw)) {
                return $raw;
            }
        }
        return '';
    }

    private static function normalize_stored_key(string $key): string {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (preg_match('/^\*+$/', $key)) {
            return '';
        }
        return $key;
    }

    private static function is_valid_secret(string $key): bool {
        return $key !== '' && preg_match('/^sk_(test|live)_\S+/', $key) === 1;
    }

    private static function is_valid_public(string $key): bool {
        return $key !== '' && preg_match('/^pk_(test|live)_\S+/', $key) === 1;
    }

    /**
     * Never mix BTI Portal secret with enrol_paystack public (or the inverse).
     *
     * @return array{secret:string,public:string,source:string,paired:bool}
     */
    private static function resolve_keys(): array {
        if (self::$resolvedkeys !== null) {
            return self::$resolvedkeys;
        }

        $btisecret = self::normalize_stored_key((string) get_config('local_btiportal', 'paystack_secret_key'));
        $btipublic = self::normalize_stored_key((string) get_config('local_btiportal', 'paystack_public_key'));
        $envsecret = getenv('BTI_PAYSTACK_SECRET_KEY');
        if ($envsecret !== false && trim($envsecret) !== '') {
            $btisecret = self::normalize_stored_key(trim($envsecret));
        }
        $envpublic = getenv('BTI_PAYSTACK_PUBLIC_KEY');
        if ($envpublic !== false && trim($envpublic) !== '') {
            $btipublic = self::normalize_stored_key(trim($envpublic));
        }

        if (self::is_valid_secret($btisecret)) {
            $paired = self::is_valid_public($btipublic);
            self::$resolvedkeys = [
                'secret' => $btisecret,
                'public' => $paired ? $btipublic : '',
                'source' => 'btiportal',
                'paired' => $paired,
            ];
            return self::$resolvedkeys;
        }

        $enrolsecret = self::enrol_paystack_key('secret');
        $enrolpublic = self::enrol_paystack_key('public');
        self::$resolvedkeys = [
            'secret' => $enrolsecret,
            'public' => $enrolpublic,
            'source' => 'enrol_paystack',
            'paired' => $enrolsecret !== '' && $enrolpublic !== '',
        ];
        return self::$resolvedkeys;
    }

    private static function key_tier(string $key): string {
        if (preg_match('/^sk_(test|live)_/', $key, $m)) {
            return $m[1];
        }
        if (preg_match('/^pk_(test|live)_/', $key, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Card popup needs a matched pk_/sk_ pair from the same Paystack account and mode. */
    public static function inline_checkout_ready(): bool {
        $keys = self::resolve_keys();
        if (empty($keys['paired']) || $keys['secret'] === '' || $keys['public'] === '') {
            return false;
        }
        if (!preg_match('/^pk_(test|live)_/', $keys['public'])) {
            return false;
        }
        if (!preg_match('/^sk_(test|live)_/', $keys['secret'])) {
            return false;
        }
        return self::key_tier($keys['secret']) === self::key_tier($keys['public']);
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
        if (!self::is_valid_secret($secret)) {
            return ['ok' => false, 'message' => self::describe_secret_key_problem($secret)];
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
            $message = (string)($decoded['message'] ?? 'Paystack request failed');
            return [
                'ok' => false,
                'message' => self::humanize_paystack_error($message),
                'httpcode' => $httpcode,
                'data' => $decoded,
            ];
        }
        return ['ok' => true, 'data' => $decoded['data'] ?? $decoded, 'httpcode' => $httpcode];
    }

    private static function describe_secret_key_problem(string $secret): string {
        if (self::is_valid_secret($secret)) {
            return '';
        }
        if (preg_match('/^pk_/', $secret)) {
            return 'The Paystack secret slot contains a public key (pk_…). Paste sk_test_… from Paystack → Settings → API Keys & Webhooks into BTI Portal or Paystack enrolment.';
        }
        if ($secret !== '') {
            return 'BTI Portal has a Paystack secret value that is not sk_test_… or sk_live_… (often a stale password field). Clear both Paystack fields under Local plugins → BTI Portal and save, then set keys only under Paystack enrolment—or paste a fresh sk_test_ key, purge caches.';
        }
        return 'Paystack secret key is not configured. Set sk_test_… under Site administration → Plugins → Local plugins → BTI Portal or Paystack enrolment (test mode), then purge Moodle caches.';
    }

    private static function humanize_paystack_error(string $message): string {
        if (stripos($message, 'invalid key') === false) {
            return $message;
        }
        return 'Paystack rejected your API secret (Invalid key). Use sk_test_ and pk_test_ from the same Paystack test account in Moodle. If BTI Portal has only one key filled in, clear it or add both keys—do not mix BTI secret with enrol Paystack public.';
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