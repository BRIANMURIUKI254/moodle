<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Convert stored fee amounts into the Paystack collection currency (display + charge).
 */
class fees_fx_helper {
    private const CACHE_KEY_RATE = 'fx_usd_kes_rate';
    private const CACHE_KEY_TIME = 'fx_usd_kes_fetched';

    /** Last resort when live FX and admin fallback are unavailable (BTI Portal → USD to KES fallback rate). */
    private const DEFAULT_USD_KES_RATE = 129.5;

    public static function collection_currency(): string {
        return paystack_client::currency_for_payment('XXX');
    }

    /**
     * @return array{ok:bool,amount?:int,currency?:string,message?:string}
     */
    public static function present_minor(int $amountminor, string $feecurrency): array {
        return self::convert_minor($amountminor, $feecurrency, self::collection_currency(), false);
    }

    /**
     * Amount/currency to send to Paystack (fails closed if FX unavailable).
     *
     * @return array{ok:bool,amount?:int,currency?:string,fee_currency?:string,message?:string}
     */
    public static function prepare_paystack_minor(int $amountminor, string $feecurrency): array {
        $target = self::collection_currency();
        $converted = self::convert_minor($amountminor, $feecurrency, $target, true);
        if (empty($converted['ok'])) {
            return $converted;
        }
        $converted['fee_currency'] = strtoupper(trim($feecurrency));
        return $converted;
    }

    /**
     * @return array{ok:bool,amount?:int,currency?:string,message?:string}
     */
    private static function convert_minor(
        int $amountminor,
        string $fromcurrency,
        string $tocurrency,
        bool $failclosed
    ): array {
        $from = strtoupper(trim($fromcurrency));
        $to = strtoupper(trim($tocurrency));
        if ($amountminor <= 0) {
            return ['ok' => false, 'message' => 'Invalid fee amount'];
        }
        if ($from === '' || $to === '') {
            return ['ok' => false, 'message' => 'Currency is missing'];
        }
        if ($from === $to) {
            return ['ok' => true, 'amount' => $amountminor, 'currency' => $to];
        }

        if ($from === 'USD' && $to === 'KES') {
            $rate = self::resolve_usd_kes_rate($failclosed);
            if ($rate === null || $rate <= 0) {
                $rate = self::DEFAULT_USD_KES_RATE;
            }
            $major = $amountminor / 100;
            $kesmajor = round($major * $rate, 2);
            $kesminor = (int) round($kesmajor * 100);
            if ($kesminor <= 0) {
                return ['ok' => false, 'message' => 'Converted amount is too small to charge'];
            }
            return ['ok' => true, 'amount' => $kesminor, 'currency' => 'KES'];
        }

        return [
            'ok' => false,
            'message' => 'This fee currency cannot be charged online. Ask admin to publish fees in '
                . $to . '.',
        ];
    }

    /**
     * @return float|null USD→KES (1 USD = X KES)
     */
    private static function resolve_usd_kes_rate(bool $failclosed): ?float {
        if (self::live_fx_enabled()) {
            $live = self::fetch_live_usd_kes_rate();
            if ($live !== null && $live > 0) {
                self::cache_rate($live);
                return $live;
            }
        }
        $cached = self::cached_usd_kes_rate();
        if ($cached !== null && $cached > 0) {
            return $cached;
        }
        $manual = self::manual_usd_kes_rate();
        if ($manual !== null && $manual > 0) {
            return $manual;
        }
        $env = getenv('BTI_FX_USD_KES_RATE');
        if ($env !== false && trim($env) !== '' && is_numeric(trim($env))) {
            $rate = (float) trim($env);
            if ($rate > 0) {
                return $rate;
            }
        }
        if (!$failclosed) {
            return self::DEFAULT_USD_KES_RATE;
        }
        return self::DEFAULT_USD_KES_RATE;
    }

    private static function manual_usd_kes_rate(): ?float {
        $raw = trim((string) get_config('local_btiportal', 'fx_usd_kes_rate'));
        if ($raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_USD_KES_RATE;
        }
        $rate = (float) $raw;
        return $rate > 0 ? $rate : self::DEFAULT_USD_KES_RATE;
    }

    private static function live_fx_enabled(): bool {
        $flag = get_config('local_btiportal', 'fx_live_enabled');
        if ($flag === false || $flag === null || $flag === '') {
            return true;
        }
        if ((int) $flag === 1) {
            return true;
        }
        $env = getenv('BTI_FX_LIVE_ENABLED');
        return $env !== false && trim($env) === '1';
    }

    private static function fetch_live_usd_kes_rate(): ?float {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $maxage = (int) get_config('local_btiportal', 'fx_cache_hours');
        if ($maxage <= 0) {
            $maxage = 24;
        }
        $fetched = (int) get_config('local_btiportal', self::CACHE_KEY_TIME);
        $cached = self::cached_usd_kes_rate();
        if ($cached !== null && $fetched > 0 && (time() - $fetched) < ($maxage * 3600)) {
            return $cached;
        }

        $curl = new \curl();
        $options = ['CURLOPT_TIMEOUT' => 8, 'CURLOPT_RETURNTRANSFER' => true];
        $response = $curl->get('https://open.er-api.com/v6/latest/USD', [], $options);
        if ($response === false || (int) $curl->get_errno() !== 0) {
            return self::cached_usd_kes_rate();
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || empty($decoded['rates']['KES'])) {
            return null;
        }
        $rate = (float) $decoded['rates']['KES'];
        return $rate > 0 ? $rate : null;
    }

    private static function cached_usd_kes_rate(): ?float {
        $raw = get_config('local_btiportal', self::CACHE_KEY_RATE);
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }
        $rate = (float) $raw;
        return $rate > 0 ? $rate : null;
    }

    private static function cache_rate(float $rate): void {
        set_config(self::CACHE_KEY_RATE, (string) $rate, 'local_btiportal');
        set_config(self::CACHE_KEY_TIME, (string) time(), 'local_btiportal');
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{items:list,total_outstanding:int,currency:string}
     */
    public static function present_bills(array $items, int $total, string $fallbackcurrency): array {
        $currency = self::collection_currency();
        $newtotal = 0;
        $out = [];
        foreach ($items as $bill) {
            $row = $bill;
            $pres = self::present_minor((int) $bill['amount'], (string) $bill['currency']);
            if (!empty($pres['ok'])) {
                $row['amount'] = (int) $pres['amount'];
                $row['currency'] = (string) $pres['currency'];
            }
            if (($bill['status'] ?? '') !== 'Paid') {
                $newtotal += (int) $row['amount'];
            }
            $out[] = $row;
        }
        if ($newtotal <= 0 && $total > 0) {
            $pres = self::present_minor($total, $fallbackcurrency);
            if (!empty($pres['ok'])) {
                $newtotal = (int) $pres['amount'];
                $currency = (string) $pres['currency'];
            }
        }
        return ['items' => $out, 'total_outstanding' => $newtotal, 'currency' => $currency];
    }
}