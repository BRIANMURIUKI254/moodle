<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Saved Paystack authorizations for repeat tuition payments (no PAN/CVV).
 */
class student_card_helper {

    public static function table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('local_btiportal_student_card');
    }

    /**
     * @return list<array{id:int,brand:string,last4:string,exp_month:int,exp_year:int,reusable:bool,is_default:bool}>
     */
    public static function list_for_user(int $userid): array {
        global $DB;
        if (!self::table_exists() || $userid <= 0) {
            return [];
        }
        $rows = $DB->get_records('local_btiportal_student_card', ['userid' => $userid], 'isdefault DESC, id DESC');
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int)$row->id,
                'brand' => (string)$row->brand,
                'last4' => (string)$row->last4,
                'exp_month' => (int)$row->exp_month,
                'exp_year' => (int)$row->exp_year,
                'reusable' => !empty($row->reusable),
                'is_default' => !empty($row->isdefault),
            ];
        }
        return $items;
    }

    public static function get_owned(int $cardid, int $userid): ?\stdClass {
        global $DB;
        if (!self::table_exists() || $cardid <= 0 || $userid <= 0) {
            return null;
        }
        $row = $DB->get_record('local_btiportal_student_card', ['id' => $cardid, 'userid' => $userid]);
        return $row ?: null;
    }

    public static function remove(int $cardid, int $userid): bool {
        global $DB;
        $row = self::get_owned($cardid, $userid);
        if (!$row) {
            return false;
        }
        $DB->delete_records('local_btiportal_student_card', ['id' => $cardid, 'userid' => $userid]);
        return true;
    }

    /**
     * Store authorization from Paystack verify/charge payload when reusable.
     *
     * @param array<string,mixed> $data Paystack transaction data (must include authorization block).
     */
    public static function maybe_store_from_transaction(int $userid, array $data, bool $assave): void {
        if (!$assave || !self::table_exists() || $userid <= 0) {
            return;
        }
        $auth = $data['authorization'] ?? null;
        if (!is_array($auth)) {
            return;
        }
        $reusable = !empty($auth['reusable']);
        $code = trim((string)($auth['authorization_code'] ?? ''));
        if ($code === '' || !$reusable) {
            return;
        }
        self::upsert_authorization($userid, $auth, true);
    }

    /**
     * @param array<string,mixed> $auth Paystack authorization object
     */
    public static function upsert_authorization(int $userid, array $auth, bool $makedefault): void {
        global $DB;

        $code = trim((string)($auth['authorization_code'] ?? ''));
        if ($code === '' || $userid <= 0) {
            return;
        }

        $brand = substr((string)($auth['brand'] ?? $auth['card_type'] ?? 'card'), 0, 32);
        $last4 = substr(preg_replace('/\D/', '', (string)($auth['last4'] ?? '')), -4);
        $expmonth = (int)($auth['exp_month'] ?? 0);
        $expyear = (int)($auth['exp_year'] ?? 0);
        $reusable = !empty($auth['reusable']) ? 1 : 0;
        $now = time();

        $existing = $DB->get_record('local_btiportal_student_card', [
            'userid' => $userid,
            'authorization_code' => $code,
        ], '*', IGNORE_MISSING);

        if ($existing) {
            $existing->brand = $brand;
            $existing->last4 = $last4;
            $existing->exp_month = $expmonth;
            $existing->exp_year = $expyear;
            $existing->reusable = $reusable;
            $existing->timemodified = $now;
            if ($makedefault) {
                self::clear_default($userid);
                $existing->isdefault = 1;
            }
            $DB->update_record('local_btiportal_student_card', $existing);
            return;
        }

        if ($makedefault) {
            self::clear_default($userid);
        }

        $DB->insert_record('local_btiportal_student_card', (object) [
            'userid' => $userid,
            'authorization_code' => $code,
            'brand' => $brand,
            'last4' => $last4,
            'exp_month' => $expmonth,
            'exp_year' => $expyear,
            'reusable' => $reusable,
            'isdefault' => $makedefault ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    private static function clear_default(int $userid): void {
        global $DB;
        $DB->set_field('local_btiportal_student_card', 'isdefault', 0, ['userid' => $userid]);
    }

    public static function default_card(int $userid): ?\stdClass {
        global $DB;
        if (!self::table_exists()) {
            return null;
        }
        $row = $DB->get_record('local_btiportal_student_card', ['userid' => $userid, 'isdefault' => 1]);
        if ($row) {
            return $row;
        }
        return $DB->get_record('local_btiportal_student_card', ['userid' => $userid], '*', IGNORE_MULTIPLE);
    }
}