<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Admission = attaching an academic placement to a Moodle user that already exists.
 *
 * This helper never creates, updates or deletes Moodle user accounts. The portal
 * owns account creation; here we only write the academic profile.
 */
class student_admission_helper {
    /**
     * Called after the portal has created the Moodle user.
     *
     * @param int $userid Existing Moodle user id.
     * @param array $placement programmeid, curriculumid and optional org/calendar ids.
     * @return array{ok:bool,record?:array,errors?:list<array{field:string,message:string}>}
     */
    public static function create_profile_for_user(int $userid, array $placement, int $actorid = 0): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }
        if ($DB->record_exists('local_btiportal_student_profile', ['userid' => $userid])) {
            return ['ok' => false, 'errors' => [[
                'field' => 'userid',
                'message' => 'This user already has a student academic profile',
            ]]];
        }

        $placement['userid'] = $userid;
        if (empty($placement['admissiondate'])) {
            $placement['admissiondate'] = time();
        }
        $placement = self::infer_org_placement($placement);

        $transaction = $DB->start_delegated_transaction();
        try {
            $result = student_profile_helper::create($placement, $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'errors' => [['field' => '', 'message' => $e->getMessage()]]];
        }

        return $result;
    }

    /**
     * Idempotent entry point: creates the profile, or updates the placement when one exists.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function admit_student(int $userid, array $placement, int $actorid = 0): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }

        $existing = $DB->get_record('local_btiportal_student_profile', ['userid' => $userid]);
        if (!$existing) {
            return self::create_profile_for_user($userid, $placement, $actorid);
        }

        $placement['userid'] = $userid;
        $placement = self::infer_org_placement($placement);
        return student_profile_helper::update((int)$existing->id, $placement, $actorid);
    }

    /**
     * Fill school/department from the programme's department when the caller left them empty.
     */
    private static function infer_org_placement(array $placement): array {
        global $DB;

        $programmeid = (int)($placement['programmeid'] ?? 0);
        if ($programmeid <= 0) {
            return $placement;
        }
        $programme = $DB->get_record('local_btiportal_programme', ['id' => $programmeid]);
        if (!$programme) {
            return $placement;
        }

        $departmentid = (int)($placement['departmentid'] ?? 0);
        if ($departmentid <= 0 && (int)$programme->departmentid > 0) {
            $departmentid = (int)$programme->departmentid;
            $placement['departmentid'] = $departmentid;
        }
        if ((int)($placement['schoolid'] ?? 0) <= 0 && $departmentid > 0) {
            $department = $DB->get_record('local_btiportal_department', ['id' => $departmentid]);
            if ($department) {
                $placement['schoolid'] = (int)$department->schoolid;
            }
        }

        return $placement;
    }
}
