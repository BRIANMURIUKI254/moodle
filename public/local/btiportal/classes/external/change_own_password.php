<?php
/**
 * Improve password-change error messages so the portal can show the real reason.
 * Also returns site password-policy requirements for UI hints.
 */
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Change the current user's password after verifying the current password.
 */
class change_own_password extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'currentpassword' => new external_value(PARAM_RAW, 'Current password'),
            'newpassword' => new external_value(PARAM_RAW, 'New password'),
        ]);
    }

    public static function execute(string $currentpassword, string $newpassword): array {
        global $USER, $CFG, $DB;
        require_once($CFG->libdir . '/moodlelib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        self::validate_parameters(self::execute_parameters(), [
            'currentpassword' => $currentpassword,
            'newpassword' => $newpassword,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        $user = $DB->get_record('user', ['id' => $USER->id, 'deleted' => 0], '*', MUST_EXIST);

        // Wrong current password — message must live in the lang string (debuginfo is
        // often stripped from REST responses when debugging is off).
        if (!validate_internal_user_password($user, $currentpassword)) {
            throw new \moodle_exception('wrongcurrentpassword', 'local_btiportal');
        }

        if ($newpassword === $currentpassword) {
            throw new \moodle_exception('passwordmustdiffer', 'local_btiportal');
        }

        // Moodle's auth/errorpasswordupdate lang string ignores {$a}, so policy
        // details were previously dropped. Use our own string with {$a}.
        $errmsg = '';
        if (!check_password_policy($newpassword, $errmsg, $user)) {
            $detail = trim(html_entity_decode(strip_tags($errmsg), ENT_QUOTES | ENT_HTML5));
            if ($detail === '') {
                $errors = get_password_policy_errors($newpassword, $user);
                $detail = implode(' ', array_map(static fn($e) => trim(strip_tags((string)$e)), $errors));
            }
            if ($detail === '') {
                $detail = self::policy_summary();
            }
            throw new \moodle_exception('passwordpolicyfailed', 'local_btiportal', '', $detail);
        }

        update_internal_user_password($user, $newpassword);
        unset_user_preference('auth_forcepasswordchange', $user);
        unset_user_preference('auth_forcepasswordchange', $USER);

        return [
            'userid' => (int)$USER->id,
            'changed' => true,
        ];
    }

    /**
     * Human-readable summary of the enabled site password policy.
     */
    public static function policy_summary(): string {
        global $CFG;
        if (empty($CFG->passwordpolicy)) {
            return 'No site password policy is enabled.';
        }
        $parts = [];
        $len = (int)($CFG->minpasswordlength ?? 0);
        if ($len > 0) {
            $parts[] = "at least {$len} characters";
        }
        $digits = (int)($CFG->minpassworddigits ?? 0);
        if ($digits > 0) {
            $parts[] = "at least {$digits} digit" . ($digits === 1 ? '' : 's');
        }
        $lower = (int)($CFG->minpasswordlower ?? 0);
        if ($lower > 0) {
            $parts[] = "at least {$lower} lowercase letter" . ($lower === 1 ? '' : 's');
        }
        $upper = (int)($CFG->minpasswordupper ?? 0);
        if ($upper > 0) {
            $parts[] = "at least {$upper} uppercase letter" . ($upper === 1 ? '' : 's');
        }
        $special = (int)($CFG->minpasswordnonalphanum ?? 0);
        if ($special > 0) {
            $parts[] = "at least {$special} special character" . ($special === 1 ? '' : 's') . " (e.g. !@#\$%)";
        }
        return $parts ? ('Must include ' . implode(', ', $parts) . '.') : 'Password policy is enabled.';
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User id'),
            'changed' => new external_value(PARAM_BOOL, 'Whether password was changed'),
        ]);
    }
}
