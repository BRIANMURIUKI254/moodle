<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/message/lib.php');

/**
 * Unpaid students may only message admin/finance contacts via portal wrappers.
 */
class fees_messaging_helper {
    /**
     * @return list<int>
     */
    public static function allowed_recipient_ids(): array {
        global $DB, $CFG;

        $ids = [];
        $admins = explode(',', $CFG->siteadmins ?? '');
        foreach ($admins as $aid) {
            $aid = (int)trim($aid);
            if ($aid > 0) {
                $ids[$aid] = true;
            }
        }

        $ctx = \context_system::instance();
        $caps = ['local/btiportal:manageacademic', 'local/btiportal:managestudentfees', 'moodle/site:config'];
        foreach ($caps as $cap) {
            $holders = get_users_by_capability($ctx, $cap, 'u.id, u.deleted, u.suspended', '', '', '', '', false);
            foreach ($holders as $u) {
                if (empty($u->deleted) && empty($u->suspended)) {
                    $ids[(int)$u->id] = true;
                }
            }
        }

        $managers = $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
              WHERE r.shortname = 'manager'"
        );
        foreach ($managers as $mid) {
            $ids[(int)$mid] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return list<array{id:int,fullname:string,email:string,username:string}>
     */
    public static function list_contacts(string $query = ''): array {
        global $DB;

        $allowed = self::allowed_recipient_ids();
        if (!$allowed) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($allowed, SQL_PARAMS_NAMED, 'fc');
        $where = "deleted = 0 AND suspended = 0 AND id {$insql}";
        if (trim($query) !== '') {
            $like = '%' . $DB->sql_like_escape(trim($query)) . '%';
            $where .= " AND (" . $DB->sql_like('firstname', ':q1', false, false)
                . " OR " . $DB->sql_like('lastname', ':q2', false, false)
                . " OR " . $DB->sql_like('email', ':q3', false, false) . ")";
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        $rows = $DB->get_records_select('user', $where, $params, 'lastname ASC, firstname ASC', 'id, firstname, lastname, email, username', 0, 40);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)$row->id,
                'fullname' => fullname($row),
                'email' => (string)$row->email,
                'username' => (string)$row->username,
            ];
        }
        return $out;
    }

    /** Unpaid students under the active gate may only use the fees messaging allowlist. */
    public static function sender_is_fees_restricted(int $fromid): bool {
        return fees_gate_helper::is_enabled()
            && fees_gate_helper::is_subject_to_gate($fromid)
            && !student_fees_helper::is_paid($fromid);
    }

    public static function can_send(int $fromid, int $toid): bool {
        if ($fromid <= 0 || $toid <= 0 || $fromid === $toid) {
            return false;
        }
        if (!self::sender_is_fees_restricted($fromid)) {
            return true;
        }
        return in_array($toid, self::allowed_recipient_ids(), true);
    }

    public static function send(int $fromid, int $toid, string $text): void {
        if (!self::can_send($fromid, $toid)) {
            throw new \moodle_exception('feesmessagingblocked', 'local_btiportal');
        }
        $text = trim($text);
        if ($text === '') {
            throw new \invalid_parameter_exception('Message text is required');
        }
        $eventdata = new \core\message\message();
        $eventdata->component = 'local_btiportal';
        $eventdata->name = 'feescontact';
        $eventdata->userfrom = $fromid;
        $eventdata->userto = $toid;
        $eventdata->subject = '';
        $eventdata->fullmessage = $text;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = $text;
        $eventdata->notification = 0;
        message_send($eventdata);
    }

    /** Document messagingallusers for audits (Site admin → Messaging). */
    public static function messagingallusers_enabled(): bool {
        global $CFG;
        return !empty($CFG->messagingallusers);
    }
}