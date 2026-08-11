<?php
namespace local_btiportal\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use core_user;
use core_text;
use moodle_url;
use stdClass;

class inactive_student_alerts extends scheduled_task {
    /** @var int Days without course access before alerting. */
    private const INACTIVITY_DAYS = 7;

    public function get_name() {
        return get_string('task_sendinactivealerts', 'local_btiportal');
    }

    public function execute() {
        global $DB;

        $adminemails = $this->get_admin_recipient_emails();
        if (empty($adminemails)) {
            mtrace('local_btiportal inactive alerts skipped: no admin recipient emails configured.');
            return;
        }

        $cutoff = time() - (self::INACTIVITY_DAYS * DAYSECS);
        $courses = $DB->get_records_sql(
            'SELECT id, fullname
               FROM {course}
              WHERE id <> :siteid
           ORDER BY id ASC',
            ['siteid' => SITEID]
        );

        foreach ($courses as $course) {
            $this->process_course($course, $adminemails, $cutoff);
        }
    }

    private function process_course(stdClass $course, array $adminemails, int $cutoff): void {
        global $DB;

        $context = \context_course::instance((int)$course->id, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $users = get_enrolled_users($context, '', 0, 'u.*', 'lastname ASC, firstname ASC', 0, 0, true);
        if (empty($users)) {
            return;
        }

        $students = [];
        foreach ($users as $user) {
            if ($this->is_alertable_student($user, $context)) {
                $students[(int)$user->id] = $user;
            }
        }
        if (empty($students)) {
            return;
        }

        $userids = array_keys($students);
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = (int)$course->id;
        $params['cutoff'] = $cutoff;

        $accessrecords = $DB->get_records_sql(
            "SELECT userid, timeaccess
               FROM {user_lastaccess}
              WHERE courseid = :courseid
                AND userid {$insql}
                AND timeaccess > 0
                AND timeaccess <= :cutoff",
            $params
        );

        $alerts = $DB->get_records_sql(
            "SELECT id, userid, lastaccess
               FROM {local_btiportal_inactive_alert}
              WHERE courseid = :courseid
                AND userid {$insql}",
            $params
        );

        $alertsbyuser = [];
        foreach ($alerts as $alert) {
            $alertsbyuser[(int)$alert->userid] = $alert;
        }

        foreach ($students as $userid => $user) {
            $accessrecord = $accessrecords[$userid] ?? null;
            $existingalert = $alertsbyuser[$userid] ?? null;

            if (!$accessrecord) {
                if ($existingalert) {
                    $DB->delete_records('local_btiportal_inactive_alert', ['id' => $existingalert->id]);
                }
                continue;
            }

            $lastaccess = (int)$accessrecord->timeaccess;
            if ($existingalert && (int)$existingalert->lastaccess === $lastaccess) {
                continue;
            }

            if ($this->send_alerts($user, $course, $adminemails, $lastaccess)) {
                $now = time();
                if ($existingalert) {
                    $existingalert->lastaccess = $lastaccess;
                    $existingalert->timenotified = $now;
                    $existingalert->timemodified = $now;
                    $DB->update_record('local_btiportal_inactive_alert', $existingalert);
                } else {
                    $DB->insert_record('local_btiportal_inactive_alert', (object) [
                        'courseid' => (int)$course->id,
                        'userid' => (int)$user->id,
                        'lastaccess' => $lastaccess,
                        'timenotified' => $now,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }
    }

    private function is_alertable_student(stdClass $user, \context_course $context): bool {
        if (!empty($user->deleted) || !empty($user->suspended) || empty($user->email)) {
            return false;
        }
        if (isguestuser($user) || is_siteadmin($user)) {
            return false;
        }
        if (has_capability('moodle/course:update', $context, $user)
            || has_capability('moodle/course:manageactivities', $context, $user)) {
            return false;
        }
        return true;
    }

    private function send_alerts(stdClass $user, stdClass $course, array $adminemails, int $lastaccess): bool {
        $studentok = $this->send_student_email($user, $course, $lastaccess);
        $adminsok = $this->send_admin_emails($user, $course, $adminemails, $lastaccess);
        return $studentok && $adminsok;
    }

    private function send_admin_emails(stdClass $user, stdClass $course, array $adminemails, int $lastaccess): bool {
        $allok = true;
        $sender = core_user::get_support_user();
        $a = (object) [
            'student' => fullname($user),
            'course' => format_string($course->fullname),
            'lastaccess' => userdate($lastaccess, get_string('strftimedatefullshort', 'langconfig')),
        ];
        $subject = get_string('inactivealertadminsubject', 'local_btiportal', $a);
        $body = get_string('inactivealertadminbody', 'local_btiportal', $a);

        foreach ($adminemails as $email) {
            $recipient = $this->build_admin_recipient($email);
            if (!email_to_user($recipient, $sender, $subject, $body, text_to_html($body))) {
                $allok = false;
                mtrace('local_btiportal inactive alert admin email failed for ' . $email);
            }
        }

        return $allok;
    }

    private function send_student_email(stdClass $user, stdClass $course, int $lastaccess): bool {
        $sender = core_user::get_support_user();
        $a = (object) [
            'firstname' => $user->firstname,
            'course' => format_string($course->fullname),
            'lastaccess' => userdate($lastaccess, get_string('strftimedatefullshort', 'langconfig'), $user->timezone),
            'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        ];
        $subject = get_string('inactivealertstudentsubject', 'local_btiportal', $a);
        $body = get_string('inactivealertstudentbody', 'local_btiportal', $a);

        if (!email_to_user($user, $sender, $subject, $body, text_to_html($body))) {
            mtrace('local_btiportal inactive alert student email failed for userid ' . $user->id);
            return false;
        }

        return true;
    }

    private function get_admin_recipient_emails(): array {
        $raw = (string)get_config('local_btiportal', 'inactivealertadminemails');
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $emails = [];
        foreach ($parts as $email) {
            $email = \core_user::clean_field(trim(\core_text::strtolower($email)), 'email');
            if ($email === '') {
                continue;
            }
            if (!validate_email($email)) {
                mtrace('local_btiportal inactive alerts ignoring invalid admin email: ' . $email);
                continue;
            }
            $emails[$email] = $email;
        }

        return array_values($emails);
    }

    private function build_admin_recipient(string $email): stdClass {
        $recipient = core_user::get_noreply_user();
        $recipient = clone $recipient;
        $recipient->id = -1;
        $recipient->email = $email;
        $recipient->firstname = get_string('inactivealertadminrecipientname', 'local_btiportal');
        $recipient->lastname = '';
        $recipient->maildisplay = 1;
        return $recipient;
    }
}
