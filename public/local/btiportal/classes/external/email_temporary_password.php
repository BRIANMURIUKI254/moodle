<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_user;

/**
 * Email a new temporary Moodle password to the user (forgot-password for the portal).
 *
 * Moodle never stores recoverable plaintext passwords, so this generates a fresh
 * temporary password, sets it, emails it, and forces a change on next login.
 */
class email_temporary_password extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Moodle username', VALUE_DEFAULT, ''),
            'email' => new external_value(PARAM_EMAIL, 'Account email', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $username = '', string $email = ''): array {
        global $CFG, $DB;

        self::validate_parameters(self::execute_parameters(), [
            'username' => $username,
            'email' => $email,
        ]);

        $username = trim($username);
        $email = trim($email);

        // Generic response — do not reveal whether the account exists.
        $ok = [
            'sent' => true,
            'message' => get_string('temppasswordemailsent', 'local_btiportal'),
        ];

        if ($username === '' && $email === '') {
            throw new \moodle_exception('temppasswordneedidentifier', 'local_btiportal');
        }

        $user = null;
        if ($username !== '') {
            $user = $DB->get_record('user', [
                'username' => core_user::clean_field($username, 'username'),
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ]);
        }
        if (!$user && $email !== '') {
            // Prefer exact email match; if multiple, take the first active manual user.
            $candidates = $DB->get_records('user', [
                'email' => $email,
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ], 'id ASC', '*', 0, 5);
            foreach ($candidates as $candidate) {
                if (empty($candidate->suspended)) {
                    $user = $candidate;
                    break;
                }
            }
        }

        if (!$user || !empty($user->suspended) || empty($user->email)) {
            // Same success message to avoid account enumeration.
            return $ok;
        }

        // Only accounts with a Moodle-managed password can be reset this way.
        $auth = get_auth_plugin($user->auth);
        if (!$auth || empty($auth->is_internal()) || !$auth->can_change_password()) {
            return $ok;
        }

        require_once($CFG->libdir . '/moodlelib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        $mailok = setnew_password_and_mail($user);
        if (!$mailok) {
            throw new \moodle_exception('temppasswordemailfailed', 'local_btiportal');
        }

        set_user_preference('auth_forcepasswordchange', 1, $user);

        return $ok;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sent' => new external_value(PARAM_BOOL, 'Whether the request was accepted'),
            'message' => new external_value(PARAM_TEXT, 'User-facing status message'),
        ]);
    }
}
