<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Notify Admissions CMS when tuition is marked paid (signed POST callback).
 */
class admissions_fees_callback_helper {
    public static function callback_url(): string {
        $url = trim((string) get_config('local_btiportal', 'admissions_fees_callback_url'));
        if ($url !== '') {
            return $url;
        }
        $env = getenv('BTI_ADMISSIONS_FEES_CALLBACK_URL');
        return $env !== false ? trim($env) : '';
    }

    public static function callback_secret(): string {
        $secret = trim((string) get_config('local_btiportal', 'admissions_fees_callback_secret'));
        if ($secret === '') {
            $env = getenv('BTI_ADMISSIONS_FEES_CALLBACK_SECRET');
            if ($env !== false) {
                $secret = trim($env);
            }
        }
        return $secret;
    }

    /**
     * @param string $source Moodle fees source: paystack, manual, migration
     */
    public static function notify_fees_paid(int $userid, string $source, string $reference = ''): void {
        global $DB;

        $url = self::callback_url();
        if ($url === '') {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, email, idnumber', IGNORE_MISSING);
        if (!$user) {
            return;
        }

        $admissionreference = trim((string) $user->idnumber);
        if ($admissionreference === '') {
            $profile = student_profile_helper::get_by_userid($userid);
            $admissionreference = trim((string) ($profile['admissionno'] ?? $profile['admission_no'] ?? ''));
        }

        $cmssource = strtolower(trim($source)) === student_fees_helper::SOURCE_PAYSTACK ? 'paystack' : 'manual';
        $paidat = gmdate('Y-m-d\TH:i:s.000\Z', time());

        $payload = [
            'userid' => $userid,
            'email' => trim((string) $user->email),
            'admissionReference' => $admissionreference,
            'source' => $cmssource,
            'paidAt' => $paidat,
        ];
        if (trim($reference) !== '') {
            $payload['reference'] = trim($reference);
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            debugging('Admissions fees callback: could not encode JSON for user ' . $userid, DEBUG_DEVELOPER);
            return;
        }

        $secret = self::callback_secret();
        $headers = ['Content-Type: application/json'];
        if ($secret !== '') {
            $headers[] = 'X-BTI-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $curl = new \curl();
        $options = [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => 15,
        ];

        $attempts = 3;
        $delayseconds = 1;
        for ($i = 0; $i < $attempts; $i++) {
            $curl->post($url, $body, $options);
            $info = $curl->get_info();
            $code = (int) ($info['http_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                return;
            }
            if ($i < $attempts - 1) {
                sleep($delayseconds);
                $delayseconds *= 2;
            }
        }
        debugging('Admissions fees callback failed for user ' . $userid . ' source ' . $cmssource, DEBUG_DEVELOPER);
    }
}