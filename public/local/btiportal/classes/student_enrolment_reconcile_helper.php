<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared student enrolment reconcile used by admission, backfill and the nightly task.
 */
class student_enrolment_reconcile_helper {
    /**
     * Site admins and staff/teachers are never enrolled as students by automation.
     *
     * @return array{eligible:bool,reason:string}
     */
    public static function eligibility(int $userid): array {
        if ($userid <= 0) {
            return ['eligible' => false, 'reason' => 'invalid_userid'];
        }
        if (is_siteadmin($userid)) {
            return ['eligible' => false, 'reason' => 'site_admin'];
        }
        try {
            $portal = portal_role_helper::resolve_for_user($userid);
        } catch (\Throwable $ignored) {
            $portal = [];
        }
        if (!empty($portal['issiteadmin']) || !empty($portal['isuniversityadmin'])) {
            return ['eligible' => false, 'reason' => 'staff_admin'];
        }
        if (($portal['portalrole'] ?? '') === 'admin') {
            return ['eligible' => false, 'reason' => 'staff_admin'];
        }
        if (!empty($portal['isinstructor'])) {
            return ['eligible' => false, 'reason' => 'staff_teacher'];
        }
        return ['eligible' => true, 'reason' => ''];
    }

    /**
     * @return array{
     *   ok:int,userid:int,skipped:int,skipreason:string,enrolled:int,reused:int,failed:int,
     *   zerocourses:int,zeroreason:string,warnings:list,items:list
     * }
     */
    public static function enrol_user(int $userid): array {
        $eligibility = self::eligibility($userid);
        if (!$eligibility['eligible']) {
            return [
                'ok' => 1,
                'userid' => $userid,
                'skipped' => 1,
                'skipreason' => $eligibility['reason'],
                'enrolled' => 0,
                'reused' => 0,
                'failed' => 0,
                'zerocourses' => 0,
                'zeroreason' => '',
                'warnings' => [],
                'items' => [],
            ];
        }

        $profile = student_profile_helper::get_by_userid($userid);
        $warnings = [];
        $zeroreason = '';
        if ($profile) {
            $warnings = programme_container_helper::readiness_warnings(
                (int)($profile['programmeid'] ?? 0),
                (int)($profile['curriculumid'] ?? 0)
            );
        }

        $result = student_course_access_helper::ensure_pathway_enrolments($userid);
        $entitled = student_assessment_entitlement_helper::entitled_course_ids($userid);
        if (!$entitled) {
            $zeroreason = self::zero_course_reason($userid, $profile, $warnings);
        }

        return [
            'ok' => (int)($result['ok'] ?? 0),
            'userid' => $userid,
            'skipped' => 0,
            'skipreason' => '',
            'enrolled' => (int)($result['enrolled'] ?? 0),
            'reused' => (int)($result['reused'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'zerocourses' => $entitled ? 0 : 1,
            'zeroreason' => $zeroreason,
            'warnings' => $warnings,
            'items' => $result['items'] ?? [],
        ];
    }

    public static function enrol_students_for_curriculum(int $curriculumid): void {
        global $DB;
        if ($curriculumid <= 0) {
            return;
        }
        $rows = $DB->get_records(
            'local_btiportal_student_profile',
            ['curriculumid' => $curriculumid, 'status' => 'active'],
            '',
            'id,userid'
        );
        foreach ($rows as $row) {
            self::enrol_user((int)$row->userid);
        }
    }

    /**
     * @return array{
     *   programmes:list,needssetup:list,skipped:list,zerocourse:list,
     *   enrolled:int,reused:int,failed:int,needssetupcount:int
     * }
     */
    public static function run_directory(bool $execute): array {
        $students = student_profile_helper::list_directory('active', 'active');
        $byprogramme = [];
        $skipped = [];
        $zerocourse = [];
        $enrolled = 0;
        $reused = 0;
        $failed = 0;

        foreach ($students as $student) {
            $userid = (int)($student['userid'] ?? 0);
            if ($userid <= 0) {
                continue;
            }
            $programmeid = (int)($student['programmeid'] ?? 0);
            $curriculumid = (int)($student['curriculumid'] ?? 0);
            if (!isset($byprogramme[$programmeid])) {
                $byprogramme[$programmeid] = [
                    'programmeid' => $programmeid,
                    'code' => (string)($student['programmecode'] ?? ''),
                    'name' => (string)($student['programmename'] ?? ''),
                    'curriculumid' => $curriculumid,
                    'students' => 0,
                    'enrolled' => 0,
                    'reused' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'zerocourse' => 0,
                ];
            }
            $byprogramme[$programmeid]['students']++;

            if (!$execute) {
                $eligibility = self::eligibility($userid);
                if (!$eligibility['eligible']) {
                    $skipped[] = self::student_label($student) + ['reason' => $eligibility['reason']];
                    $byprogramme[$programmeid]['skipped']++;
                    continue;
                }
                $entitled = student_assessment_entitlement_helper::entitled_course_ids($userid);
                if (!$entitled) {
                    $warnings = programme_container_helper::readiness_warnings($programmeid, $curriculumid);
                    $reason = self::zero_course_reason($userid, $student, $warnings);
                    $zerocourse[] = self::student_label($student) + ['reason' => $reason];
                    $byprogramme[$programmeid]['zerocourse']++;
                }
                continue;
            }

            $result = self::enrol_user($userid);
            $enrolled += (int)$result['enrolled'];
            $reused += (int)$result['reused'];
            $failed += (int)$result['failed'];
            $byprogramme[$programmeid]['enrolled'] += (int)$result['enrolled'];
            $byprogramme[$programmeid]['reused'] += (int)$result['reused'];
            $byprogramme[$programmeid]['failed'] += (int)$result['failed'];
            if (!empty($result['skipped'])) {
                $skipped[] = self::student_label($student) + ['reason' => $result['skipreason']];
                $byprogramme[$programmeid]['skipped']++;
            }
            if (!empty($result['zerocourses'])) {
                $zerocourse[] = self::student_label($student) + ['reason' => $result['zeroreason']];
                $byprogramme[$programmeid]['zerocourse']++;
            }
        }

        $needssetup = self::needs_setup_programmes($byprogramme);

        return [
            'programmes' => array_values($byprogramme),
            'needssetup' => $needssetup,
            'skipped' => $skipped,
            'zerocourse' => $zerocourse,
            'enrolled' => $enrolled,
            'reused' => $reused,
            'failed' => $failed,
            'needssetupcount' => count($needssetup),
        ];
    }

    /**
     * Enrolments the current entitlement rules would not create. Read-only.
     *
     * @return list<array>
     */
    public static function unexpected_enrolments(): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        if ($studentroleid <= 0) {
            return [];
        }
        $sql = "SELECT ue.id, ue.userid, e.courseid, c.shortname, c.fullname
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id AND ra.roleid = :roleid
                 WHERE ue.status = 0 AND e.courseid > 1";
        $rows = $DB->get_records_sql($sql, [
            'ctxlevel' => CONTEXT_COURSE,
            'roleid' => $studentroleid,
        ]);
        $out = [];
        foreach ($rows as $row) {
            $userid = (int)$row->userid;
            $courseid = (int)$row->courseid;
            $eligibility = self::eligibility($userid);
            $profile = student_profile_helper::get_by_userid($userid);
            if (!$eligibility['eligible']) {
                $out[] = [
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'shortname' => (string)$row->shortname,
                    'fullname' => (string)$row->fullname,
                    'reason' => 'non_student:' . $eligibility['reason'],
                ];
                continue;
            }
            if (!$profile) {
                $out[] = [
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'shortname' => (string)$row->shortname,
                    'fullname' => (string)$row->fullname,
                    'reason' => 'no_student_profile',
                ];
                continue;
            }
            if (student_assessment_entitlement_helper::is_entitled($userid, $courseid)) {
                continue;
            }
            $out[] = [
                'userid' => $userid,
                'courseid' => $courseid,
                'shortname' => (string)$row->shortname,
                'fullname' => (string)$row->fullname,
                'reason' => 'not_in_entitlement',
            ];
        }
        return $out;
    }

    /**
     * @param array<int,array> $byprogramme
     * @return list<array>
     */
    public static function needs_setup_programmes(array $byprogramme): array {
        global $DB;
        $out = [];
        $programmes = $DB->get_records('local_btiportal_programme', null, 'id ASC', 'id,code,name');
        foreach ($programmes as $programme) {
            $programmeid = (int)$programme->id;
            $warnings = programme_container_helper::readiness_warnings($programmeid, 0);
            $curricula = $DB->get_records('local_btiportal_curriculum', ['programmeid' => $programmeid], 'id ASC', 'id,code,name');
            $emptycurricula = [];
            foreach ($curricula as $curriculum) {
                $cw = programme_container_helper::readiness_warnings($programmeid, (int)$curriculum->id);
                foreach ($cw as $warning) {
                    if (($warning['field'] ?? '') === 'curriculumid') {
                        $emptycurricula[] = (int)$curriculum->id;
                    }
                }
            }
            $students = (int)($byprogramme[$programmeid]['students'] ?? 0);
            if (!$warnings && !$emptycurricula) {
                continue;
            }
            $out[] = [
                'programmeid' => $programmeid,
                'code' => (string)$programme->code,
                'name' => (string)$programme->name,
                'students' => $students,
                'nocontainer' => $warnings ? 1 : 0,
                'emptycurricula' => $emptycurricula,
                'messages' => array_column($warnings, 'message'),
            ];
        }
        return $out;
    }

    /**
     * @param array|null $profile
     * @param list<array{field:string,message:string}> $warnings
     */
    private static function zero_course_reason(int $userid, ?array $profile, array $warnings): string {
        if (!$profile) {
            return 'no_student_profile';
        }
        if ($warnings) {
            return implode('; ', array_column($warnings, 'message'));
        }
        return 'no_entitled_moodle_courses';
    }

    /**
     * @return array{userid:int,label:string,programmeid:int,curriculumid:int}
     */
    private static function student_label(array $student): array {
        $userid = (int)($student['userid'] ?? 0);
        $name = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
        return [
            'userid' => $userid,
            'label' => trim($name . ' #' . $userid . ' ' . (string)($student['admissionnumber'] ?? '')),
            'programmeid' => (int)($student['programmeid'] ?? 0),
            'curriculumid' => (int)($student['curriculumid'] ?? 0),
        ];
    }
}
