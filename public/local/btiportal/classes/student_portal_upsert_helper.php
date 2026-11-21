<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Admissions CMS upsert: Moodle user + academic profile + unpaid fees row — no enrolment.
 *
 * programme_id must match {local_btiportal_programme}.id (same IDs as the academic catalogue).
 */
class student_portal_upsert_helper {
    /**
     * @return array{ok:bool,userid?:int,errors?:list}
     */
    public static function upsert(array $input, int $actorid = 0): array {
        global $DB;

        $userid = (int)($input['userid'] ?? 0);
        $username = trim((string)($input['username'] ?? ''));
        if ($userid <= 0 && $username !== '') {
            global $CFG;
            $user = $DB->get_record('user', [
                'username' => $username,
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ]);
            if (!$user) {
                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
            }
            if ($user) {
                $userid = (int)$user->id;
            }
        }
        if ($userid <= 0) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'User not found']]];
        }

        $programmeid = (int)($input['programme_id'] ?? $input['programmeid'] ?? 0);
        if ($programmeid <= 0 || !$DB->record_exists('local_btiportal_programme', ['id' => $programmeid])) {
            return ['ok' => false, 'errors' => [[
                'field' => 'programme_id',
                'message' => 'programme_id must be the primary key id from local_btiportal_programme (not an external CMS id unless synced)',
            ]]];
        }

        $admissionno = trim((string)($input['admission_no'] ?? ''));
        if ($admissionno !== '') {
            $DB->set_field('user', 'idnumber', $admissionno, ['id' => $userid]);
        }

        $placement = [
            'userid' => $userid,
            'programmeid' => $programmeid,
            'curriculumid' => self::resolve_default_curriculum_id($programmeid),
            'academicyearid' => (int)($input['academic_year'] ?? $input['academicyearid'] ?? 0),
            'semesterid' => (int)($input['semester'] ?? $input['semesterid'] ?? 0),
            'admissionstatus' => strtolower(trim((string)($input['admissionstatus'] ?? 'admitted'))),
            'advisoruserid' => (int)($input['advisor_userid'] ?? 0),
        ];
        $yearofstudy = (int)($input['year_of_study'] ?? 0);
        if ($yearofstudy > 0) {
            $placement['curriculumyearid'] = self::resolve_curriculum_year_id($placement['curriculumid'], $yearofstudy);
        }

        $placement = student_admission_helper::infer_org_placement_public($placement);

        $existing = student_profile_helper::get_by_userid($userid);
        if ($existing) {
            $result = student_profile_helper::update((int)$existing['id'], $placement, $actorid);
        } else {
            if ($placement['curriculumid'] <= 0) {
                return ['ok' => false, 'errors' => [[
                    'field' => 'curriculumid',
                    'message' => 'No active curriculum found for this programme — configure curriculum in the academic catalogue first',
                ]]];
            }
            $result = student_profile_helper::create($placement, $actorid);
        }

        if (!$result['ok']) {
            return $result;
        }

        student_fees_helper::ensure_unpaid_row($userid, $actorid);

        return ['ok' => true, 'userid' => $userid, 'record' => $result['record'] ?? null];
    }

    private static function resolve_default_curriculum_id(int $programmeid): int {
        global $DB;
        $curricula = $DB->get_records(
            'local_btiportal_curriculum',
            ['programmeid' => $programmeid, 'status' => 'active'],
            'id DESC',
            'id',
            0,
            1
        );
        if ($curricula) {
            return (int)reset($curricula)->id;
        }
        $any = $DB->get_records('local_btiportal_curriculum', ['programmeid' => $programmeid], 'id DESC', 'id', 0, 1);
        return $any ? (int)reset($any)->id : 0;
    }

    private static function resolve_curriculum_year_id(int $curriculumid, int $yearnumber): int {
        global $DB;
        if ($curriculumid <= 0 || $yearnumber <= 0) {
            return 0;
        }
        $row = $DB->get_record('local_btiportal_curriculum_year', [
            'curriculumid' => $curriculumid,
            'yearnumber' => $yearnumber,
        ]);
        return $row ? (int)$row->id : 0;
    }
}