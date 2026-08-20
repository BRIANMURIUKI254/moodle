<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Create a Moodle student identity without Moodle's core "New user account" email.
 *
 * A real temporary password is generated later by admission_mailer (same
 * generate_password() path as setnew_password_and_mail). This helper only
 * inserts the user with a throwaway password and forces a change on login.
 */
class student_account_helper {
    /**
     * @param array{
     *   firstname?:string,lastname?:string,middlename?:string,email?:string,
     *   phone1?:string,address?:string,city?:string,country?:string,department?:string
     * } $input
     * @return array{id:int,username:string,admissionnumber:string}
     */
    public static function create(array $input): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/moodlelib.php');

        $email = \core_text::strtolower(trim((string)($input['email'] ?? '')));
        $firstname = trim((string)($input['firstname'] ?? ''));
        $lastname = trim((string)($input['lastname'] ?? ''));
        if ($firstname === '' || $lastname === '' || $email === '') {
            throw new \moodle_exception('invalidparameter', 'webservice', '', null,
                'First name, last name, and email are required.');
        }
        if (!validate_email($email)) {
            throw new \invalid_parameter_exception('Email address is invalid: ' . $email);
        }

        $select = $DB->sql_equal('email', ':email', false) . ' AND mnethostid = :mnethostid AND deleted = 0';
        if ($DB->record_exists_select('user', $select, [
            'email' => $email,
            'mnethostid' => $CFG->mnet_localhost_id,
        ])) {
            throw new \invalid_parameter_exception('Email address already exists: ' . $email);
        }

        $lock = admission_number_helper::acquire_lock();
        try {
            $admissionnumber = admission_number_helper::next_unused();
            while (admission_number_helper::is_taken($admissionnumber)) {
                if (!preg_match('#^BTI/(\d{4})/(\d+)$#', $admissionnumber, $matches)) {
                    break;
                }
                $admissionnumber = admission_number_helper::format((int)$matches[1], ((int)$matches[2]) + 1);
            }

            $username = self::unique_username($email);
            $throwaway = generate_password();

            $user = (object) [
                'auth' => 'manual',
                'username' => $username,
                'password' => $throwaway,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'idnumber' => $admissionnumber,
                'mailformat' => 1,
            ];
            $middlename = trim((string)($input['middlename'] ?? ''));
            if ($middlename !== '') {
                $user->middlename = $middlename;
            }
            foreach (['phone1', 'address', 'city', 'country', 'department'] as $field) {
                $value = trim((string)($input[$field] ?? ''));
                if ($value !== '') {
                    $user->{$field} = $value;
                }
            }

            $transaction = $DB->start_delegated_transaction();
            $userid = user_create_user($user, true, false);
            identity_helper::ensure_force_password_change($userid);
            unset_user_preference('create_password', (object)['id' => $userid]);
            $transaction->allow_commit();
        } finally {
            if ($lock) {
                $lock->release();
            }
        }

        \core\event\user_created::create_from_userid($userid)->trigger();
        service_auth_helper::ensure_portal_authorised($userid);

        $created = $DB->get_record('user', ['id' => $userid], 'id, username, idnumber', MUST_EXIST);
        return [
            'id' => (int)$created->id,
            'username' => (string)$created->username,
            'admissionnumber' => (string)$created->idnumber,
        ];
    }

    public static function unique_username(string $email): string {
        global $CFG, $DB;

        $local = strtolower(trim($email));
        $parts = explode('@', $local, 2);
        $base = preg_replace('/[^a-z0-9._-]/', '', $parts[0] ?? '');
        if ($base === '') {
            $base = 'student';
        }
        $base = substr($base, 0, 100);

        $username = $base;
        $suffix = 1;
        while ($DB->record_exists('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
        ])) {
            $suffix++;
            $username = substr($base, 0, 90) . $suffix;
        }
        return $username;
    }
}
