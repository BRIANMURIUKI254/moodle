<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Self-scoped student Moodle course access for curriculum Course Units.
 *
 * Pathway membership is local_btiportal. Actual LMS access remains Moodle enrolment.
 */
class student_course_access_helper {
    /**
     * Enrol the student onto every mapped Moodle course in their current curriculum.
     * Reuses existing Moodle users and enrolments. Does not create courses or users.
     *
     * @return array{ok:bool,userid:int,items:list,enrolled:int,reused:int,failed:int}
     */
    public static function ensure_pathway_enrolments(int $userid): array {
        $items = [];
        $enrolled = 0;
        $reused = 0;
        $failed = 0;
        foreach (self::pathway_units($userid) as $unit) {
            $courseid = (int)($unit['moodlecourseid'] ?? 0);
            if ($courseid <= 1) {
                $items[] = self::format_access_row($unit, 'unmapped', 0, 0, 'Course Unit is not connected to Moodle.');
                continue;
            }
            $result = enrolment_transition_helper::ensure_student_enrolment($userid, $courseid);
            if (!empty($result['ok'])) {
                $enrolled += (int)$result['enrolled'];
                $reused += (int)$result['reused'];
                $access = self::course_access($userid, $courseid, $unit);
                $items[] = $access;
            } else {
                $failed++;
                $items[] = self::format_access_row(
                    $unit,
                    'not_enrolled',
                    0,
                    0,
                    'Your Moodle enrolment for this Course Unit is not active. Please contact Academic Administration.'
                );
            }
        }
        return [
            'ok' => $failed === 0 ? 1 : 0,
            'userid' => $userid,
            'moodleuserid' => $userid,
            'items' => $items,
            'enrolled' => $enrolled,
            'reused' => $reused,
            'failed' => $failed,
            'count' => count($items),
        ];
    }

    /**
     * @return array{courseunitid:int,code:string,name:string,moodlecourseid:int,enrolled:int,accessible:int,accesscode:string,accessmessage:string}
     */
    public static function course_access(int $userid, int $courseid, ?array $unit = null): array {
        global $DB;

        if ($unit === null) {
            $unit = self::unit_for_course($userid, $courseid);
        }
        if (!$unit) {
            $enrolled = 0;
            if ($courseid > 1) {
                try {
                    $context = \context_course::instance($courseid);
                    $enrolled = is_enrolled($context, $userid, '', true) ? 1 : 0;
                } catch (\Throwable $e) {
                    $enrolled = 0;
                }
            }
            return [
                'courseunitid' => 0,
                'code' => '',
                'name' => '',
                'moodlecourseid' => $courseid,
                'enrolled' => $enrolled,
                'accessible' => 0,
                'accesscode' => 'not_in_pathway',
                'accessmessage' => 'This Moodle course is not part of your current curriculum.',
            ];
        }
        if ($courseid <= 1) {
            return self::format_access_row($unit, 'unmapped', 0, 0, 'This Course Unit is not connected to Moodle.');
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return self::format_access_row($unit, 'missing_moodle_course', 0, 0, 'This Course Unit is currently unavailable. Please contact Academic Administration.');
        }

        $user = \core_user::get_user($userid);
        $context = \context_course::instance($courseid);
        $enrolled = is_enrolled($context, $userid, '', true) ? 1 : 0;
        $accessible = 0;
        if ($user && $enrolled) {
            $accessible = can_access_course($course, $user) ? 1 : 0;
        }

        if (!$enrolled) {
            return self::format_access_row(
                $unit,
                'not_enrolled',
                0,
                0,
                'Your Moodle enrolment for this Course Unit is not active. Please contact Academic Administration.'
            );
        }
        if (!$accessible) {
            return self::format_access_row(
                $unit,
                'inaccessible',
                1,
                0,
                'This Course Unit is currently unavailable. Please contact Academic Administration.'
            );
        }
        return self::format_access_row($unit, 'ok', 1, 1, '');
    }

    /**
     * @param array $pathway
     * @return array
     */
    public static function annotate_pathway(int $userid, array $pathway): array {
        foreach (['current', 'studied', 'upcoming'] as $bucket) {
            if (empty($pathway[$bucket]) || !is_array($pathway[$bucket])) {
                continue;
            }
            foreach ($pathway[$bucket] as $i => $unit) {
                $courseid = (int)($unit['moodlecourseid'] ?? 0);
                $access = self::course_access($userid, $courseid, $unit);
                $pathway[$bucket][$i]['moodleenrolled'] = $access['enrolled'];
                $pathway[$bucket][$i]['moodleaccessible'] = $access['accessible'];
                $pathway[$bucket][$i]['accesscode'] = $access['accesscode'];
                $pathway[$bucket][$i]['accessmessage'] = $access['accessmessage'];
            }
        }
        return $pathway;
    }

    /**
     * @return list<array>
     */
    public static function pathway_units(int $userid): array {
        $pathway = student_unit_pathway_helper::get_pathway($userid);
        $out = [];
        $seen = [];
        foreach (array_merge($pathway['current'] ?? [], $pathway['studied'] ?? [], $pathway['upcoming'] ?? []) as $unit) {
            $id = (int)($unit['courseunitid'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $unit;
        }
        return $out;
    }

    private static function unit_for_course(int $userid, int $courseid): ?array {
        foreach (self::pathway_units($userid) as $unit) {
            if ((int)($unit['moodlecourseid'] ?? 0) === $courseid) {
                return $unit;
            }
        }
        return null;
    }

    private static function format_access_row(array $unit, string $code, int $enrolled, int $accessible, string $message): array {
        return [
            'courseunitid' => (int)($unit['courseunitid'] ?? $unit['id'] ?? 0),
            'code' => (string)($unit['code'] ?? ''),
            'name' => (string)($unit['name'] ?? ''),
            'moodlecourseid' => (int)($unit['moodlecourseid'] ?? 0),
            'enrolled' => $enrolled,
            'accessible' => $accessible,
            'accesscode' => $code,
            'accessmessage' => $message,
        ];
    }

    public static function item_structure_keys(): array {
        return [
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'enrolled' => new \core_external\external_value(PARAM_INT, 'enrolled'),
            'accessible' => new \core_external\external_value(PARAM_INT, 'accessible'),
            'accesscode' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'accesscode'),
            'accessmessage' => new \core_external\external_value(PARAM_TEXT, 'accessmessage'),
        ];
    }

    public static function ensure_structure_keys(): array {
        return [
            'ok' => new \core_external\external_value(PARAM_INT, '1 when every mapped unit could be enrolled or reused'),
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'moodleuserid' => new \core_external\external_value(PARAM_INT, 'moodleuserid'),
            'items' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure(self::item_structure_keys())
            ),
            'enrolled' => new \core_external\external_value(PARAM_INT, 'enrolled'),
            'reused' => new \core_external\external_value(PARAM_INT, 'reused'),
            'failed' => new \core_external\external_value(PARAM_INT, 'failed'),
            'count' => new \core_external\external_value(PARAM_INT, 'count'),
        ];
    }
}
