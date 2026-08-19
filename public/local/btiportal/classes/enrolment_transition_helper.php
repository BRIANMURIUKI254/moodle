<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle course enrolment transitions for student lifecycle actions.
 *
 * Academic placement is owned by local_btiportal; Moodle remains source of truth
 * for enrolments, grades, and activity history. Grades and completed history are
 * never deleted by these helpers.
 */
class enrolment_transition_helper {
    /**
     * Live preview of the student's current Moodle course enrolments.
     *
     * @return array{enrolments:list<array>,warning:string,manualfollowup:int}
     */
    public static function preview(int $userid): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return [
                'enrolments' => [],
                'warning' => 'Moodle user not found.',
                'manualfollowup' => 1,
            ];
        }

        $enrolments = self::list_user_enrolments($userid);
        return [
            'enrolments' => $enrolments,
            'warning' => 'Academic placement changes do not automatically remove historical Moodle learning records.',
            'manualfollowup' => 0,
            'proposed' => [
                'preserve' => array_column($enrolments, 'courseid'),
                'end' => [],
                'unenrol' => [],
                'add' => [],
                'manualreview' => [],
            ],
        ];
    }

    /**
     * Apply a configured enrolment policy. Never deletes the Moodle user or grades.
     *
     * @param list<int> $courseidstoenrol
     * @param list<int> $courseidstounenrol
     * @return array{ok:bool,result:array,errors?:list}
     */
    public static function apply(
        int $userid,
        string $policy,
        array $courseidstoenrol = [],
        array $courseidstounenrol = []
    ): array {
        global $DB;

        $policy = strtolower(trim($policy));
        if (!in_array($policy, academic_constants::ENROLMENT_POLICIES, true)) {
            return [
                'ok' => false,
                'result' => [],
                'errors' => [['field' => 'enrolmentpolicy', 'message' => 'Invalid enrolment policy']],
            ];
        }

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return [
                'ok' => false,
                'result' => [],
                'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']],
            ];
        }

        $current = self::list_user_enrolments($userid);
        $preserved = [];
        $ended = [];
        $unenrolled = [];
        $enrolled = [];
        $manual = [];
        $messages = [];

        if ($policy === 'preserve' || $policy === 'manual_followup') {
            foreach ($current as $row) {
                $preserved[] = (int)$row['courseid'];
            }
            if ($policy === 'manual_followup') {
                $manual = $preserved;
                $messages[] = 'Moodle course enrolments require manual follow-up; academic placement only was updated.';
            } else {
                $messages[] = 'Existing Moodle course enrolments were preserved.';
            }
            return [
                'ok' => true,
                'result' => self::result_payload($preserved, $ended, $unenrolled, $enrolled, $manual, $messages, $policy),
            ];
        }

        $activecourseids = [];
        foreach ($current as $row) {
            if ((int)$row['status'] === 0) {
                $activecourseids[] = (int)$row['courseid'];
            } else {
                $preserved[] = (int)$row['courseid'];
            }
        }

        if ($policy === 'end_active' || $policy === 'end_and_enrol' || $policy === 'unenrol_previous') {
            $targets = $courseidstounenrol ?: $activecourseids;
            foreach ($targets as $courseid) {
                $courseid = (int)$courseid;
                if ($courseid <= 0) {
                    continue;
                }
                $ok = self::end_or_unenrol($userid, $courseid, $policy === 'unenrol_previous');
                if ($ok['ended']) {
                    $ended[] = $courseid;
                }
                if ($ok['unenrolled']) {
                    $unenrolled[] = $courseid;
                }
                if ($ok['manual']) {
                    $manual[] = $courseid;
                    $messages[] = $ok['message'];
                }
            }
        }

        if ($policy === 'enrol_new' || $policy === 'end_and_enrol') {
            foreach ($courseidstoenrol as $courseid) {
                $courseid = (int)$courseid;
                if ($courseid <= 0) {
                    continue;
                }
                $ok = self::enrol_student($userid, $courseid);
                if ($ok['enrolled']) {
                    $enrolled[] = $courseid;
                } else {
                    $manual[] = $courseid;
                    $messages[] = $ok['message'];
                }
            }
        }

        // Courses not touched remain preserved.
        foreach ($activecourseids as $cid) {
            if (!in_array($cid, $ended, true) && !in_array($cid, $unenrolled, true)) {
                $preserved[] = $cid;
            }
        }

        if (!$messages) {
            $messages[] = 'Enrolment transition applied with policy: ' . $policy;
        }

        return [
            'ok' => true,
            'result' => self::result_payload($preserved, $ended, $unenrolled, $enrolled, $manual, $messages, $policy),
        ];
    }

    /**
     * @return list<array>
     */
    public static function list_user_enrolments(int $userid): array {
        global $DB;

        $sql = "SELECT ue.id, ue.status, ue.timestart, ue.timeend, ue.timecreated,
                       e.enrol, e.courseid, c.fullname, c.shortname, c.visible,
                       r.shortname AS roleshortname
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
             LEFT JOIN {role_assignments} ra ON ra.userid = ue.userid
                       AND ra.contextid = (
                           SELECT ctx.id FROM {context} ctx
                            WHERE ctx.contextlevel = :coursecxt
                              AND ctx.instanceid = e.courseid
                       )
             LEFT JOIN {role} r ON r.id = ra.roleid
                 WHERE ue.userid = :userid
                   AND c.id <> :siteid
              ORDER BY c.fullname ASC, ue.id ASC";

        $rows = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'siteid' => SITEID,
            'coursecxt' => CONTEXT_COURSE,
        ], 0, academic_constants::LIST_LIMIT);

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = (int)$row->courseid . ':' . (int)$row->id;
            if (isset($seen[$key])) {
                // Prefer student role label when multiple role joins appear.
                if (($row->roleshortname ?? '') === 'student') {
                    $seen[$key]['role'] = 'student';
                }
                continue;
            }
            $item = [
                'enrolmentid' => (int)$row->id,
                'courseid' => (int)$row->courseid,
                'fullname' => (string)$row->fullname,
                'shortname' => (string)$row->shortname,
                'visible' => (int)$row->visible,
                'enrol' => (string)$row->enrol,
                'status' => (int)$row->status,
                'statuslabel' => ((int)$row->status === 0) ? 'active' : 'inactive',
                'role' => (string)($row->roleshortname ?? ''),
                'timestart' => (int)$row->timestart,
                'timeend' => (int)$row->timeend,
                'timecreated' => (int)$row->timecreated,
                'completion' => 'unknown',
            ];
            $seen[$key] = $item;
            $out[] = $item;
        }
        return array_values($seen);
    }

    /**
     * @return array{ended:bool,unenrolled:bool,manual:bool,message:string}
     */
    private static function end_or_unenrol(int $userid, int $courseid, bool $hardunenrol): array {
        global $DB;

        $instances = $DB->get_records('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
        if (!$instances) {
            return [
                'ended' => false,
                'unenrolled' => false,
                'manual' => true,
                'message' => "No manual enrolment instance for course {$courseid}; requires manual follow-up.",
            ];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            return [
                'ended' => false,
                'unenrolled' => false,
                'manual' => true,
                'message' => 'Manual enrolment plugin unavailable; requires manual follow-up.',
            ];
        }

        foreach ($instances as $instance) {
            $ue = $DB->get_record('user_enrolments', [
                'enrolid' => $instance->id,
                'userid' => $userid,
            ]);
            if (!$ue) {
                continue;
            }
            if ($hardunenrol) {
                $plugin->unenrol_user($instance, $userid);
                return ['ended' => false, 'unenrolled' => true, 'manual' => false, 'message' => ''];
            }
            // Soft-end: suspend enrolment without deleting history/grades.
            $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED, $ue->timestart, time());
            return ['ended' => true, 'unenrolled' => false, 'manual' => false, 'message' => ''];
        }

        return [
            'ended' => false,
            'unenrolled' => false,
            'manual' => true,
            'message' => "No active manual enrolment found for course {$courseid}.",
        ];
    }

    /**
     * @return array{enrolled:bool,message:string}
     */
    private static function enrol_student(int $userid, int $courseid): array {
        global $DB;

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return ['enrolled' => false, 'message' => "Moodle course {$courseid} not found."];
        }

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            return ['enrolled' => false, 'message' => "No manual enrolment instance for course {$courseid}."];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            return ['enrolled' => false, 'message' => 'Manual enrolment plugin unavailable.'];
        }

        $role = $DB->get_record('role', ['shortname' => 'student']);
        $roleid = $role ? (int)$role->id : 0;
        $plugin->enrol_user($instance, $userid, $roleid, 0, 0, ENROL_USER_ACTIVE);
        return ['enrolled' => true, 'message' => ''];
    }

    /**
     * Enrol or reactivate a student on a Moodle course. Reuses the existing user and enrolment.
     *
     * @return array{ok:bool,enrolled:int,reused:int,message:string}
     */
    public static function ensure_student_enrolment(int $userid, int $courseid): array {
        global $DB;

        if ($userid <= 0 || $courseid <= 1) {
            return ['ok' => false, 'enrolled' => 0, 'reused' => 0, 'message' => 'User or Moodle course is invalid'];
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'enrolled' => 0, 'reused' => 0, 'message' => 'Moodle user not found'];
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return ['ok' => false, 'enrolled' => 0, 'reused' => 0, 'message' => "Moodle course {$courseid} not found"];
        }

        $context = \context_course::instance($courseid);
        if (is_enrolled($context, $userid, '', true)) {
            return ['ok' => true, 'enrolled' => 0, 'reused' => 1, 'message' => 'Already enrolled'];
        }

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            return ['ok' => false, 'enrolled' => 0, 'reused' => 0, 'message' => "No manual enrolment instance for course {$courseid}"];
        }
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            return ['ok' => false, 'enrolled' => 0, 'reused' => 0, 'message' => 'Manual enrolment plugin unavailable'];
        }

        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if ($ue) {
            $plugin->update_user_enrol($instance, $userid, ENROL_USER_ACTIVE, 0, 0);
            return ['ok' => true, 'enrolled' => 1, 'reused' => 1, 'message' => 'Enrolment reactivated'];
        }

        $role = $DB->get_record('role', ['shortname' => 'student']);
        $roleid = $role ? (int)$role->id : 0;
        $plugin->enrol_user($instance, $userid, $roleid, 0, 0, ENROL_USER_ACTIVE);
        return ['ok' => true, 'enrolled' => 1, 'reused' => 0, 'message' => 'Enrolled'];
    }

    /**
     * @param list<int> $preserved
     * @param list<int> $ended
     * @param list<int> $unenrolled
     * @param list<int> $enrolled
     * @param list<int> $manual
     * @param list<string> $messages
     */
    private static function result_payload(
        array $preserved,
        array $ended,
        array $unenrolled,
        array $enrolled,
        array $manual,
        array $messages,
        string $policy
    ): array {
        return [
            'policy' => $policy,
            'preserved' => array_values(array_unique(array_map('intval', $preserved))),
            'ended' => array_values(array_unique(array_map('intval', $ended))),
            'unenrolled' => array_values(array_unique(array_map('intval', $unenrolled))),
            'enrolled' => array_values(array_unique(array_map('intval', $enrolled))),
            'manualreview' => array_values(array_unique(array_map('intval', $manual))),
            'messages' => array_values($messages),
            'gradespreserved' => 1,
            'historypreserved' => 1,
            'userdeleted' => 0,
        ];
    }

    public static function enrolment_structure_keys(): array {
        return [
            'enrolmentid' => new \core_external\external_value(PARAM_INT, 'enrolmentid'),
            'courseid' => new \core_external\external_value(PARAM_INT, 'courseid'),
            'fullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
            'shortname' => new \core_external\external_value(PARAM_TEXT, 'shortname'),
            'visible' => new \core_external\external_value(PARAM_INT, 'visible'),
            'enrol' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'enrol'),
            'status' => new \core_external\external_value(PARAM_INT, 'status'),
            'statuslabel' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'statuslabel'),
            'role' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'role'),
            'timestart' => new \core_external\external_value(PARAM_INT, 'timestart'),
            'timeend' => new \core_external\external_value(PARAM_INT, 'timeend'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'completion' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'completion'),
        ];
    }

    public static function result_structure_keys(): array {
        return [
            'policy' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'policy'),
            'preserved' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_INT, 'courseid')
            ),
            'ended' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_INT, 'courseid')
            ),
            'unenrolled' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_INT, 'courseid')
            ),
            'enrolled' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_INT, 'courseid')
            ),
            'manualreview' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_INT, 'courseid')
            ),
            'messages' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_TEXT, 'message')
            ),
            'gradespreserved' => new \core_external\external_value(PARAM_INT, 'gradespreserved'),
            'historypreserved' => new \core_external\external_value(PARAM_INT, 'historypreserved'),
            'userdeleted' => new \core_external\external_value(PARAM_INT, 'userdeleted'),
        ];
    }

    public static function preview_structure_keys(): array {
        return [
            'enrolments' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(self::enrolment_structure_keys())
            ),
            'warning' => new \core_external\external_value(PARAM_TEXT, 'warning'),
            'manualfollowup' => new \core_external\external_value(PARAM_INT, 'manualfollowup'),
            'proposed' => new \core_external\external_single_structure([
                'preserve' => new \core_external\external_multiple_structure(
                    new \core_external\external_value(PARAM_INT, 'courseid')
                ),
                'end' => new \core_external\external_multiple_structure(
                    new \core_external\external_value(PARAM_INT, 'courseid')
                ),
                'unenrol' => new \core_external\external_multiple_structure(
                    new \core_external\external_value(PARAM_INT, 'courseid')
                ),
                'add' => new \core_external\external_multiple_structure(
                    new \core_external\external_value(PARAM_INT, 'courseid')
                ),
                'manualreview' => new \core_external\external_multiple_structure(
                    new \core_external\external_value(PARAM_INT, 'courseid')
                ),
            ], 'proposed', VALUE_OPTIONAL),
        ];
    }
}
