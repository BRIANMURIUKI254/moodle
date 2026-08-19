<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Compare a student's Moodle enrolments with every Course Unit on their curriculum.
 *
 * Never auto-unenrols. Legacy enrolments are reported for explicit admin confirmation.
 */
class student_enrolment_audit_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function audit(int $userid): array {
        global $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }

        $profile = student_profile_helper::get_by_userid($userid) ?? [];
        $curriculumid = (int)($profile['curriculumid'] ?? 0);
        $curriculumname = (string)($profile['curriculumname'] ?? '');
        if ($curriculumname === '' && $curriculumid > 0) {
            $curriculumname = (string)$DB->get_field('local_btiportal_curriculum', 'name', ['id' => $curriculumid]);
        }
        $units = $curriculumid > 0 ? curriculum_unit_helper::list_assignments($curriculumid) : [];

        $planbycourse = [];
        $items = [];
        foreach ($units as $unit) {
            $courseid = (int)($unit['moodlecourseid'] ?? $unit['courseid'] ?? 0);
            $code = (string)($unit['code'] ?? '');
            $classification = 'unmapped';
            if ($courseid > 1) {
                $classification = 'missing';
                $planbycourse[$courseid] = $unit;
            }
            $items[] = self::format_item($unit, $courseid, $classification);
        }

        $enrolments = enrolment_transition_helper::list_user_enrolments($userid);
        $enrolledids = [];
        foreach ($enrolments as $enrol) {
            $courseid = (int)($enrol['courseid'] ?? 0);
            if ($courseid <= 1) {
                continue;
            }
            if ((string)($enrol['role'] ?? '') !== '' && (string)$enrol['role'] !== 'student') {
                continue;
            }
            $enrolledids[$courseid] = $enrol;
        }

        foreach ($items as $i => $item) {
            $courseid = (int)$item['moodlecourseid'];
            if ($courseid > 1 && isset($enrolledids[$courseid])) {
                $items[$i]['classification'] = 'in_plan';
                $items[$i]['classificationlabel'] = 'In Plan';
                $items[$i]['enrolled'] = 1;
            }
        }

        foreach ($enrolledids as $courseid => $enrol) {
            if (isset($planbycourse[$courseid])) {
                continue;
            }
            $items[] = [
                'courseunitid' => 0,
                'code' => (string)($enrol['shortname'] ?? ''),
                'name' => (string)($enrol['fullname'] ?? ''),
                'moodlecourseid' => $courseid,
                'moodlecourseshortname' => (string)($enrol['shortname'] ?? ''),
                'moodlecoursename' => (string)($enrol['fullname'] ?? ''),
                'mappingstatus' => 'legacy',
                'classification' => 'legacy',
                'classificationlabel' => 'Legacy / Not In Current Plan',
                'enrolled' => 1,
                'yearofstudy' => 0,
                'semestersequence' => 0,
                'message' => 'Moodle enrolment is not part of the current curriculum. It will not be removed automatically.',
            ];
        }

        $summary = ['in_plan' => 0, 'missing' => 0, 'legacy' => 0, 'unmapped' => 0];
        foreach ($items as $item) {
            $key = (string)$item['classification'];
            if (isset($summary[$key])) {
                $summary[$key]++;
            }
        }

        return [
            'ok' => true,
            'record' => [
                'userid' => $userid,
                'curriculumid' => $curriculumid,
                'curriculumname' => $curriculumname,
                'items' => $items,
                'summary' => $summary,
                'warning' => 'Legacy Moodle enrolments are preserved until an administrator confirms removal.',
            ],
        ];
    }

    /**
     * Remove one legacy enrolment after explicit confirmation. Never deletes the Moodle user.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function remove_legacy(int $userid, int $courseid, bool $confirm, int $actorid = 0): array {
        if (!$confirm) {
            return ['ok' => false, 'errors' => [[
                'field' => 'confirm',
                'message' => 'Removing a legacy Moodle enrolment requires explicit administrator confirmation',
            ]]];
        }
        if ($courseid <= 1) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Moodle course id is required']]];
        }

        $audit = self::audit($userid);
        if (!$audit['ok']) {
            return $audit;
        }
        $legacy = false;
        foreach ($audit['record']['items'] as $item) {
            if ((int)$item['moodlecourseid'] === $courseid && ($item['classification'] ?? '') === 'legacy') {
                $legacy = true;
                break;
            }
        }
        if (!$legacy) {
            return ['ok' => false, 'errors' => [[
                'field' => 'courseid',
                'message' => 'That Moodle course is in the current curriculum plan and cannot be removed as legacy',
            ]]];
        }

        $result = enrolment_transition_helper::apply($userid, 'unenrol_previous', [], [$courseid]);
        if (!$result['ok']) {
            return $result;
        }
        $refreshed = self::audit($userid);
        return [
            'ok' => true,
            'record' => [
                'userid' => $userid,
                'removedcourseid' => $courseid,
                'actorid' => $actorid,
                'userdeleted' => 0,
                'gradespreserved' => 1,
                'transition' => $result['result'] ?? null,
                'audit' => $refreshed['ok'] ? $refreshed['record'] : null,
            ],
        ];
    }

    private static function format_item(array $unit, int $courseid, string $classification): array {
        $labels = [
            'in_plan' => 'In Plan',
            'missing' => 'Missing / Should Be Enrolled',
            'legacy' => 'Legacy / Not In Current Plan',
            'unmapped' => 'Unmapped',
        ];
        $message = '';
        if ($classification === 'unmapped') {
            $message = 'Course Unit is not mapped to Moodle.';
        } else if ($classification === 'missing') {
            $message = 'Student is not enrolled in the mapped Moodle course.';
        }
        return [
            'courseunitid' => (int)($unit['id'] ?? 0),
            'code' => (string)($unit['code'] ?? ''),
            'name' => (string)($unit['name'] ?? ''),
            'moodlecourseid' => $courseid,
            'moodlecourseshortname' => (string)($unit['moodlecourseshortname'] ?? ''),
            'moodlecoursename' => (string)($unit['moodlecoursename'] ?? ''),
            'mappingstatus' => (string)($unit['mappingstatus'] ?? ($courseid > 1 ? 'mapped' : 'unmapped')),
            'classification' => $classification,
            'classificationlabel' => $labels[$classification] ?? $classification,
            'enrolled' => 0,
            'yearofstudy' => (int)($unit['yearofstudy'] ?? 0),
            'semestersequence' => (int)($unit['semestersequence'] ?? 0),
            'message' => $message,
        ];
    }

    public static function item_structure_keys(): array {
        return [
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'moodlecourseshortname' => new \core_external\external_value(PARAM_TEXT, 'moodlecourseshortname'),
            'moodlecoursename' => new \core_external\external_value(PARAM_TEXT, 'moodlecoursename'),
            'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
            'classification' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'classification'),
            'classificationlabel' => new \core_external\external_value(PARAM_TEXT, 'classificationlabel'),
            'enrolled' => new \core_external\external_value(PARAM_INT, 'enrolled'),
            'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy'),
            'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
        ];
    }

    public static function audit_structure_keys(): array {
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'curriculumname' => new \core_external\external_value(PARAM_TEXT, 'curriculumname'),
            'items' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(self::item_structure_keys())
            ),
            'summary' => new \core_external\external_single_structure([
                'in_plan' => new \core_external\external_value(PARAM_INT, 'in_plan'),
                'missing' => new \core_external\external_value(PARAM_INT, 'missing'),
                'legacy' => new \core_external\external_value(PARAM_INT, 'legacy'),
                'unmapped' => new \core_external\external_value(PARAM_INT, 'unmapped'),
            ]),
            'warning' => new \core_external\external_value(PARAM_TEXT, 'warning'),
        ];
    }
}
