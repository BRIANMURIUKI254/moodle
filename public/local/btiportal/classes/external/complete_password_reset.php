<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/** Set a Moodle password using a valid one-use reset token emailed to the user. */
class complete_password_reset extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'token' => new external_value(PARAM_ALPHANUMEXT, 'One-use password reset token'),
            'newpassword' => new external_value(PARAM_RAW, 'New password'),
        ]);
    }

    public static function execute(string $token, string $newpassword): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/moodlelib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        self::validate_parameters(self::execute_parameters(), compact('token', 'newpassword'));
        if ($token === '' || $newpassword === '') {
            throw new \invalid_parameter_exception('Enter a valid reset link and a new password.');
        }
        $record = $DB->get_record('user_password_resets', ['token' => $token]);
        $resetwindow = (int)($CFG->pwresettime ?? 1800);
        if (!$record || (int)$record->timerequested < time() - $resetwindow) {
            if ($record) {
                $DB->delete_records('user_password_resets', ['id' => $record->id]);
            }
            throw new \moodle_exception('forgotteninvalidurl');
        }
        $user = $DB->get_record('user', ['id' => $record->userid, 'deleted' => 0, 'suspended' => 0], '*', IGNORE_MISSING);
        if (!$user || empty($user->confirmed) || isguestuser($user)
                || $user->auth === 'nologin'
                || !\core\di::get(\core\authentication::class)->is_enabled($user->auth)) {
            throw new \moodle_exception('forgotteninvalidurl');
        }
        $auth = get_auth_plugin($user->auth);
        if (!$auth || !$auth->can_reset_password() || !$auth->is_internal()) {
            throw new \moodle_exception('forgotteninvalidurl');
        }
        $errmsg = '';
        if (!check_password_policy($newpassword, $errmsg, $user)) {
            $detail = trim(html_entity_decode(strip_tags($errmsg), ENT_QUOTES | ENT_HTML5));
            if ($detail === '') {
                $detail = implode(' ', array_map(
                    static fn($message): string => trim(strip_tags((string)$message)),
                    get_password_policy_errors($newpassword, $user)
                ));
            }
            if ($detail === '') {
                $detail = get_string('passwordpolicyfailed', 'local_btiportal');
            }
            throw new \moodle_exception('passwordpolicyfailed', 'local_btiportal', '', $detail);
        }

        $transaction = $DB->start_delegated_transaction();
        if (!$auth->user_update_password($user, $newpassword)) {
            throw new \moodle_exception('errorpasswordupdate', 'auth');
        }
        user_add_password_history((int)$user->id, $newpassword);
        $DB->delete_records('user_password_resets', ['id' => $record->id]);
        unset_user_preference('auth_forcepasswordchange', $user);
        unset_user_preference('create_password', $user);
        login_unlock_account($user);
        \core\session\manager::destroy_user_sessions((int)$user->id);
        $transaction->allow_commit();

        return ['changed' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'changed' => new external_value(PARAM_BOOL, 'Whether Moodle stored the new password'),
        ]);
    }
}
