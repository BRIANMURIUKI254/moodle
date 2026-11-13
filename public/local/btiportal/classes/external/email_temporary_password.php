<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_user;

/** Request a single-use Moodle password reset token and email the frontend link. */
class email_temporary_password extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Moodle username', VALUE_DEFAULT, ''),
            'email' => new external_value(PARAM_EMAIL, 'Account email', VALUE_DEFAULT, ''),
            'portalurl' => new external_value(PARAM_RAW_TRIMMED, 'Ignored; reset links use the configured portal URL', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $username = '', string $email = '', string $portalurl = ''): array {
        global $CFG, $DB, $SITE;

        self::validate_parameters(self::execute_parameters(), compact('username', 'email', 'portalurl'));
        require_once($CFG->dirroot . '/login/lib.php');

        $username = trim($username);
        $email = trim($email);
        if ($username === '' && $email === '') {
            throw new \moodle_exception('temppasswordneedidentifier', 'local_btiportal');
        }

        $generic = [
            'sent' => true,
            'message' => get_string('passwordresetrequestaccepted', 'local_btiportal'),
        ];
        if ($username !== '') {
            $user = $DB->get_record('user', [
                'username' => core_user::clean_field($username, 'username'),
                'deleted' => 0,
                'suspended' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ]);
        } else {
            $user = $DB->get_record_sql(
                "SELECT * FROM {user}
                  WHERE " . $DB->sql_equal('email', ':email', false, false) . "
                    AND mnethostid = :mnethostid AND deleted = 0 AND suspended = 0",
                ['email' => $email, 'mnethostid' => $CFG->mnet_localhost_id],
                IGNORE_MULTIPLE
            );
        }
        if (!$user || empty($user->confirmed) || empty($user->email)) {
            return $generic;
        }

        $auth = get_auth_plugin($user->auth);
        if (!$auth || !$auth->can_reset_password() || !$auth->is_internal()
                || !\core\di::get(\core\authentication::class)->is_enabled($user->auth)
                || !has_capability('moodle/user:changeownpassword', \context_system::instance(), $user->id)) {
            return $generic;
        }

        // Never accept a reset-link host from the public request: it could leak a bearer token.
        $portal = trim((string)get_config('local_btiportal', 'portalurl'));
        if ($portal === '') {
            throw new \moodle_exception('portalurlmissing', 'local_btiportal');
        }
        $portal = rtrim($portal, '/');
        $parsed = clean_param($portal, PARAM_URL);
        if ($parsed === '' || !preg_match('#^https://#i', $parsed)) {
            debugging('Password reset link not sent: configure a valid HTTPS portal URL.', DEBUG_NORMAL);
            return $generic;
        }
        $portal = preg_replace('#/login/?$#i', '', $parsed);

        $reset = $DB->get_record('user_password_resets', ['userid' => $user->id]);
        $resetwindow = (int)($CFG->pwresettime ?? 1800);
        if (!$reset || (int)$reset->timerequested < time() - $resetwindow) {
            if ($reset) {
                $DB->delete_records('user_password_resets', ['id' => $reset->id]);
            }
            $reset = core_login_generate_password_reset($user);
        } else if (empty($reset->timererequested)) {
            $reset->timererequested = time();
            $DB->update_record('user_password_resets', $reset);
        } else {
            return $generic;
        }

        $link = $portal . '/forgot-password?token=' . rawurlencode((string)$reset->token);
        $sitename = format_string((string)$SITE->fullname);
        $subject = get_string('passwordresetemailsubject', 'local_btiportal', $sitename);
        $a = (object)['sitename' => $sitename, 'link' => $link];
        $body = get_string('passwordresetemailbody', 'local_btiportal', $a);
        $html = '<p>' . s(get_string('passwordresetemailintro', 'local_btiportal', $sitename)) . '</p>'
            . '<p><a href="' . s($link) . '">' . s(get_string('passwordresetemailbutton', 'local_btiportal')) . '</a></p>'
            . '<p>' . s(get_string('passwordresetemailexpiry', 'local_btiportal', (int)ceil($resetwindow / 60))) . '</p>';
        if (!email_to_user($user, core_user::get_support_user(), $subject, $body, $html)) {
            throw new \moodle_exception('temppasswordemailfailed', 'local_btiportal');
        }
        return $generic;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sent' => new external_value(PARAM_BOOL, 'Whether the request was accepted'),
            'message' => new external_value(PARAM_TEXT, 'Generic reset request message'),
        ]);
    }
}
