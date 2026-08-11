<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Calendar-driven student semester unlock.
 *
 * When the student's institutional semester end date has passed (or a newer
 * institutional semester is marked current), advances curriculum placement to
 * the next semester the admin registered on the curriculum, and enrols the
 * new delivery-plan Moodle courses.
 */
class student_semester_sync_helper {
    /**
     * Auto-advance one step when the institutional timeframe is due.
     *
     * @return array{advanced:bool,notice:string,result?:array}
     */
    public static function sync_if_due(int $userid, int $actorid = 0): array {
        global $DB;

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile || (string)($profile['academicstatus'] ?? '') !== 'active') {
            return ['advanced' => false, 'notice' => ''];
        }

        $semesterid = (int)($profile['semesterid'] ?? 0);
        if ($semesterid <= 0) {
            return ['advanced' => false, 'notice' => ''];
        }

        $instsem = $DB->get_record('local_btiportal_semester', ['id' => $semesterid]);
        if (!$instsem) {
            return ['advanced' => false, 'notice' => ''];
        }

        $now = time();
        $ended = (int)$instsem->enddate > 0 && (int)$instsem->enddate < $now;
        $current = $DB->get_record('local_btiportal_semester', ['iscurrent' => 1], '*', IGNORE_MULTIPLE);
        $movedcurrent = $current
            && (int)$current->id !== $semesterid
            && (int)$current->startdate > 0
            && (int)$current->startdate <= $now
            && ((int)$instsem->enddate <= 0 || (int)$instsem->enddate < $now
                || (int)$current->startdate >= (int)$instsem->enddate);

        if (!$ended && !$movedcurrent) {
            return ['advanced' => false, 'notice' => ''];
        }

        $nextinst = self::resolve_next_institutional_semester($instsem, $current);
        if (!$nextinst) {
            return [
                'advanced' => false,
                'notice' => 'Institutional semester ended, but the next semester has not been registered yet.',
            ];
        }

        $result = student_academic_progression_helper::progress_semester(
            $userid,
            [
                'academicyearid' => (int)$nextinst->academicyearid,
                'semesterid' => (int)$nextinst->id,
                'reason' => 'Automatic semester unlock after institutional timeframe ended',
                'notes' => 'Synced from institutional calendar for student dashboard units',
                'effectivedate' => $now,
            ],
            $actorid,
            true
        );

        if (empty($result['ok'])) {
            $msg = $result['errors'][0]['message'] ?? 'Could not unlock the next semester automatically.';
            return ['advanced' => false, 'notice' => $msg];
        }

        return [
            'advanced' => true,
            'notice' => 'Next semester units are now available on your dashboard.',
            'result' => $result['result'] ?? null,
        ];
    }

    /**
     * Cron entry — advance all active students whose institutional semester is due.
     *
     * @return array{checked:int,advanced:int}
     */
    public static function sync_all_due(int $limit = 200): array {
        global $DB;

        $now = time();
        $sql = "SELECT sp.userid
                  FROM {local_btiportal_student_profile} sp
                  JOIN {local_btiportal_semester} s ON s.id = sp.semesterid
                 WHERE sp.academicstatus = 'active'
                   AND sp.semesterid > 0
                   AND (
                        (s.enddate > 0 AND s.enddate < ?)
                     OR EXISTS (
                            SELECT 1
                              FROM {local_btiportal_semester} cur
                             WHERE cur.iscurrent = 1
                               AND cur.id <> s.id
                               AND cur.startdate > 0
                               AND cur.startdate <= ?
                        )
                   )
              ORDER BY sp.userid ASC";
        $rows = $DB->get_records_sql($sql, [$now, $now], 0, max(1, $limit));
        $advanced = 0;
        foreach ($rows as $row) {
            $out = self::sync_if_due((int)$row->userid, 0);
            if (!empty($out['advanced'])) {
                $advanced++;
            }
        }
        return ['checked' => count($rows), 'advanced' => $advanced];
    }

    /**
     * @param \stdClass $currentstudent
     * @param \stdClass|false|null $globalcurrent
     * @return \stdClass|null
     */
    private static function resolve_next_institutional_semester($currentstudent, $globalcurrent) {
        global $DB;

        if ($globalcurrent && (int)$globalcurrent->id !== (int)$currentstudent->id) {
            return $globalcurrent;
        }

        $nextsameyear = $DB->get_record_sql(
            "SELECT *
               FROM {local_btiportal_semester}
              WHERE academicyearid = ?
                AND status <> 'archived'
                AND sequence > ?
           ORDER BY sequence ASC",
            [(int)$currentstudent->academicyearid, (int)$currentstudent->sequence],
            IGNORE_MULTIPLE
        );
        if ($nextsameyear) {
            return $nextsameyear;
        }

        $year = $DB->get_record('local_btiportal_academic_year', ['id' => (int)$currentstudent->academicyearid]);
        if (!$year) {
            return null;
        }
        $nextyear = $DB->get_record_sql(
            "SELECT *
               FROM {local_btiportal_academic_year}
              WHERE status <> 'archived'
                AND startdate > ?
           ORDER BY startdate ASC",
            [(int)$year->startdate],
            IGNORE_MULTIPLE
        );
        if (!$nextyear) {
            return null;
        }
        return $DB->get_record_sql(
            "SELECT *
               FROM {local_btiportal_semester}
              WHERE academicyearid = ?
                AND status <> 'archived'
           ORDER BY sequence ASC",
            [(int)$nextyear->id],
            IGNORE_MULTIPLE
        ) ?: null;
    }
}
