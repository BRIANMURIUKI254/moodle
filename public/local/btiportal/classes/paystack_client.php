<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal Paystack REST client (server-side only).
 */
class paystack_client {
    private const API_BASE = 'https://api.paystack.co';

    public static function secret_key(): string {
        $key = trim((string) get_config('local_btiportal', 'paystack_secret_key'));
        if ($key === '') {
            $env = getenv('BTI_PAYSTACK_SECRET_KEY');
            if ($env !== false && trim($env) !== '') {
                $key = trim($env);
            }
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
        return $key;
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

        $payload = [
            'email' => $email,
            'amount' => $amountminor,
            'currency' => strtoupper($currency),
            'reference' => $reference,
            'callback_url' => $callbackurl,
            'metadata' => $metadata,
        ];

        return self::request('POST', '/transaction/initialize', $payload, $secret);
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

        $payload = [
            'email' => $email,
            'amount' => $amountminor,
            'currency' => strtoupper($currency),
            'authorization_code' => $authorizationcode,
            'reference' => $reference,
        ];

        return self::request('POST', '/transaction/charge_authorization', $payload, $secret);
    }

    /** @return array{ok:bool,data?:array,message?:string,httpcode?:int} */
    public static function verify_transaction(string $reference): array {
        $secret = self::secret_key();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Paystack secret key is not configured'];
        }
        $ref = rawurlencode($reference);
        return self::request('GET', '/transaction/verify/' . $ref, null, $secret);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,data?:array,message?:string,httpcode?:int}
     */
    private static function request(string $method, string $path, ?array $body, string $secret): array {
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