<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

use core_user;

/**
 * Formal BTI College admission welcome email.
 *
 * Generates a Moodle temporary password with the same generate_password()
 * path as setnew_password_and_mail(), forces a change on next login, and
 * sends via email_to_user() (Gmail SMTP). Forgot-password continues to use
 * email_temporary_password.php unchanged.
 */
class admission_mailer {
    /**
     * Generate (optional) temporary password, force a change, and email the student.
     *
     * @param array{programmename?:string,portalurl?:string,includepassword?:bool,admissionnumber?:string} $options
     * @return array{sent:bool,userid:int,includepassword:bool,message:string}
     */
    public static function send(int $userid, array $options = []): array {
        global $CFG, $DB, $SITE;

        require_once($CFG->libdir . '/moodlelib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user || empty($user->email)) {
            return [
                'sent' => false,
                'userid' => $userid,
                'includepassword' => false,
                'message' => get_string('admissionwelcomeemailnouser', 'local_btiportal'),
            ];
        }

        $includepassword = array_key_exists('includepassword', $options)
            ? (bool)$options['includepassword']
            : true;
        $password = '';
        if ($includepassword) {
            $auth = get_auth_plugin($user->auth);
            if (!$auth || empty($auth->is_internal()) || !$auth->can_change_password()) {
                $includepassword = false;
            }
        }
        if ($includepassword) {
            $password = self::set_temporary_password($user);
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        }

        $programmename = self::resolve_programme_name($userid, (string)($options['programmename'] ?? ''));
        $admissionnumber = trim((string)($options['admissionnumber'] ?? ''));
        if ($admissionnumber === '') {
            $admissionnumber = trim((string)($user->idnumber ?? ''));
        }
        $portallogin = self::portal_login_url((string)($options['portalurl'] ?? ''));
        $sitename = format_string($SITE->fullname);
        if (trim($sitename) === '') {
            $sitename = 'BTI College';
        }
        $studentname = fullname($user);
        if (trim($studentname) === '') {
            $studentname = trim($user->firstname . ' ' . $user->lastname);
        }

        $a = (object) [
            'studentname' => $studentname,
            'firstname' => $user->firstname,
            'programmename' => $programmename !== '' ? $programmename : get_string('admissionwelcomeprogrammefallback', 'local_btiportal'),
            'sitename' => $sitename,
            'portalurl' => $portallogin,
            'username' => $user->username,
            'temppassword' => $password,
            'admissionnumber' => $admissionnumber !== '' ? $admissionnumber : get_string('admissionwelcomeidfallback', 'local_btiportal'),
            'supportemail' => !empty($CFG->supportemail) ? (string)$CFG->supportemail : '',
            'supportline' => !empty($CFG->supportemail)
                ? 'Support email: ' . $CFG->supportemail
                : '',
        ];

        $subject = get_string('admissionwelcomesubject', 'local_btiportal', $a);
        $body = $includepassword
            ? get_string('admissionwelcomebody', 'local_btiportal', $a)
            : get_string('admissionwelcomebodyexisting', 'local_btiportal', $a);
        $html = self::html_body($a, $includepassword, $portallogin);

        $sender = core_user::get_support_user();
        $ok = email_to_user($user, $sender, $subject, $body, $html);
        if (!$ok) {
            return [
                'sent' => false,
                'userid' => $userid,
                'includepassword' => $includepassword,
                'message' => get_string('admissionwelcomeemailfailed', 'local_btiportal'),
            ];
        }

        return [
            'sent' => true,
            'userid' => $userid,
            'includepassword' => $includepassword,
            'message' => get_string('admissionwelcomeemailsent', 'local_btiportal'),
        ];
    }

    /**
     * Same password-generation path as setnew_password_and_mail(), without sending
     * Moodle's generic new-user email.
     */
    private static function set_temporary_password(\stdClass $user): string {
        $newpassword = generate_password();
        try {
            \core\di::get(\core\authentication\password::class)->update($user, $newpassword, false);
        } catch (\Throwable $e) {
            update_internal_user_password($user, $newpassword);
        }
        set_user_preference('auth_forcepasswordchange', 1, $user);
        unset_user_preference('create_password', $user);
        return $newpassword;
    }

    public static function portal_login_url(string $provided = ''): string {
        global $CFG;

        $base = trim($provided);
        if ($base === '') {
            $base = trim((string)get_config('local_btiportal', 'portalurl'));
        }
        if ($base === '') {
            $base = rtrim((string)$CFG->wwwroot, '/');
        }
        $base = rtrim($base, '/');
        if ($base === '') {
            return '/login';
        }
        if (preg_match('#/login/?$#i', $base)) {
            return $base;
        }
        return $base . '/login';
    }

    private static function resolve_programme_name(int $userid, string $provided): string {
        global $DB;

        $provided = trim($provided);
        if ($provided !== '') {
            return $provided;
        }
        if (!$DB->get_manager()->table_exists('local_btiportal_student_profile')
            || !$DB->get_manager()->table_exists('local_btiportal_programme')) {
            return '';
        }
        $profile = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid]);
        if (!$profile || (int)($profile->programmeid ?? 0) <= 0) {
            return '';
        }
        $programme = $DB->get_record('local_btiportal_programme', ['id' => (int)$profile->programmeid]);
        if (!$programme) {
            return '';
        }
        $code = trim((string)($programme->code ?? ''));
        $name = trim((string)($programme->name ?? ''));
        if ($code !== '' && $name !== '') {
            return $code . ' — ' . $name;
        }
        return $name !== '' ? $name : $code;
    }

    private static function logo_path(): ?string {
        $path = __DIR__ . '/../pix/ouk-logo.png';
        return is_readable($path) ? $path : null;
    }

    /**
     * Embedded data-URI plus a hosted fallback so Gmail still shows the mark.
     *
     * @return array{datauri:string,url:string}
     */
    private static function logo_sources(string $portallogin): array {
        global $CFG;

        $datauri = '';
        $path = self::logo_path();
        if ($path) {
            $bytes = file_get_contents($path);
            if ($bytes !== false && $bytes !== '') {
                $datauri = 'data:image/png;base64,' . base64_encode($bytes);
            }
        }

        $url = '';
        $origin = preg_replace('#/login/?$#i', '', $portallogin);
        $origin = is_string($origin) ? rtrim($origin, '/') : '';
        if ($origin !== '' && preg_match('#^https?://#i', $origin)) {
            $url = $origin . '/ouk-logo.png';
        }
        if ($url === '' && !empty($CFG->wwwroot)) {
            $url = (new \moodle_url('/local/btiportal/pix/ouk-logo.png'))->out(false);
        }

        return ['datauri' => $datauri, 'url' => $url];
    }

    private static function html_body(object $a, bool $includepassword, string $portallogin): string {
        $student = s($a->studentname);
        $programme = s($a->programmename);
        $site = s($a->sitename);
        $portal = s($a->portalurl);
        $username = s($a->username);
        $password = s($a->temppassword);
        $admission = s($a->admissionnumber);
        $support = trim((string)$a->supportemail);
        $supportline = $support !== ''
            ? '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#334155;">If you need assistance, contact us at <a href="mailto:' . s($support) . '" style="color:#003366;">' . s($support) . '</a>.</p>'
            : '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#334155;">If you need assistance, contact the Admissions Office or your institution’s e-learning support team.</p>';

        $logo = self::logo_sources($portallogin);
        $imgsrc = $logo['datauri'] !== '' ? $logo['datauri'] : $logo['url'];
        $logohtml = $imgsrc !== ''
            ? '<img src="' . s($imgsrc) . '" alt="BTI College" width="72" height="72" style="display:block;border:0;width:72px;height:auto;margin:0 0 10px;" />'
            : '';

        $credentials = '';
        if ($includepassword) {
            $credentials = '
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px;border:1px solid #dbe3ef;border-radius:8px;">
                  <tr><td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:13px;color:#64748b;">Learning portal</td>
                      <td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:14px;"><a href="' . $portal . '" style="color:#003366;font-weight:600;">' . $portal . '</a></td></tr>
                  <tr><td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:13px;color:#64748b;">Admission number</td>
                      <td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:14px;font-family:Consolas,Menlo,monospace;color:#0f172a;">' . $admission . '</td></tr>
                  <tr><td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:13px;color:#64748b;">Username</td>
                      <td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:14px;font-family:Consolas,Menlo,monospace;color:#0f172a;">' . $username . '</td></tr>
                  <tr><td style="padding:14px 16px;font-size:13px;color:#64748b;">Temporary password</td>
                      <td style="padding:14px 16px;font-size:14px;font-family:Consolas,Menlo,monospace;color:#0f172a;">' . $password . '</td></tr>
                </table>';
        } else {
            $credentials = '
                <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#334155;">Please sign in with your existing username <strong>' . $username . '</strong> at <a href="' . $portal . '" style="color:#003366;">' . $portal . '</a>.</p>
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px;border:1px solid #dbe3ef;border-radius:8px;">
                  <tr><td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:13px;color:#64748b;">Learning portal</td>
                      <td style="padding:14px 16px;border-bottom:1px solid #dbe3ef;font-size:14px;"><a href="' . $portal . '" style="color:#003366;font-weight:600;">' . $portal . '</a></td></tr>
                  <tr><td style="padding:14px 16px;font-size:13px;color:#64748b;">Admission number</td>
                      <td style="padding:14px 16px;font-size:14px;font-family:Consolas,Menlo,monospace;color:#0f172a;">' . $admission . '</td></tr>
                </table>';
        }

        $passwordsteps = $includepassword
            ? '<li style="margin:0 0 8px;">Sign in with the username and temporary password above.</li>
               <li style="margin:0 0 8px;">You will be asked to choose a new password. Please do this immediately and do not share the temporary password.</li>'
            : '<li style="margin:0 0 8px;">Sign in with your existing account username and password.</li>';

        return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Georgia,\'Times New Roman\',serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fb;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
        <tr><td style="background:#003366;padding:22px 28px;">
          ' . $logohtml . '
          <div style="font-size:13px;letter-spacing:0.16em;text-transform:uppercase;color:#F5A623;font-family:Arial,sans-serif;">Admissions</div>
          <div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:4px;font-family:Arial,sans-serif;">BTI College</div>
        </td></tr>
        <tr><td style="padding:28px;">
          <p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#0f172a;">Dear ' . $student . ',</p>
          <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#334155;">Congratulations, you have been admitted to <strong>BTI College</strong>.</p>
          <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#334155;">On behalf of ' . $site . ', we are delighted to welcome you to <strong>' . $programme . '</strong>. Your admission has been confirmed, and your learning portal account is ready.</p>
          <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#334155;">Please keep this message confidential. It contains the details you need to begin your studies.</p>
          ' . $credentials . '
          <p style="margin:0 0 8px;font-size:15px;font-weight:700;color:#003366;font-family:Arial,sans-serif;">Next steps</p>
          <ol style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:1.7;color:#334155;">
            <li style="margin:0 0 8px;">Open the learning portal: <a href="' . $portal . '" style="color:#003366;">' . $portal . '</a></li>
            ' . $passwordsteps . '
            <li style="margin:0 0 8px;">Review your dashboard, enrolled units, timetable, and announcements.</li>
            <li style="margin:0;">Update your profile photo and contact details so we can reach you during the semester.</li>
          </ol>
          <p style="margin:0 0 24px;">
            <a href="' . $portal . '" style="display:inline-block;background:#003366;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-family:Arial,sans-serif;font-size:14px;font-weight:700;">Open the learning portal</a>
          </p>
          ' . $supportline . '
          <p style="margin:0 0 4px;font-size:14px;line-height:1.6;color:#0f172a;">We look forward to supporting your studies.</p>
          <p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#0f172a;">Yours sincerely,<br><strong>Admissions Office</strong><br>BTI College</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
    }
}
