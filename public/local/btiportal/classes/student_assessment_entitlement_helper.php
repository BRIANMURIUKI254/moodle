<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Single source of truth for which Moodle courses a programme student may
 * see assessments on and be enrolled into.
 *
 * Entitlement is not "current semester only". It includes:
 * - the programme container course (Final Exams, unitid = 0)
 * - mapped units for the current semester
 * - mapped units already studied (carry-over / earlier semesters)
 * - upcoming units the student is already enrolled in (resit)
 */
class student_assessment_entitlement_helper {
    public const KIND_PROGRAMME_CONTAINER = 'programme_container';
    public const KIND_CURRENT_UNIT = 'current_unit';
    public const KIND_STUDIED_UNIT = 'studied_unit';
    public const KIND_UPCOMING_UNIT = 'upcoming_unit';
    public const KIND_RESIT = 'resit_or_carryover';
    public const KIND_NEITHER = 'neither';

    /**
     * @return array{
     *   userid:int,
     *   programme_code:string,
     *   currentyear:int,
     *   currentsemester:int,
     *   courses:array<int,array{
     *     kind:string,
     *     courseid:int,
     *     course_shortname:string,
     *     unitcode:string,
     *     unitname:string,
     *     programme_code:string
     *   }>
     * }
     */
    public static function for_user(int $userid): array {
        global $DB;

        $empty = [
            'userid' => $userid,
            'programme_code' => '',
            'currentyear' => 0,
            'currentsemester' => 0,
            'courses' => [],
        ];
        if ($userid <= 0) {
            return $empty;
        }

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile
            || strtolower(trim((string)($profile['status'] ?? ''))) !== 'active'
            || strtolower(trim((string)($profile['admissionstatus'] ?? ''))) !== 'active'
            || strtolower(trim((string)($profile['academicstatus'] ?? ''))) !== 'active'
            || (int)($profile['programmeid'] ?? 0) <= 0
        ) {
            return $empty;
        }

        $programme = $DB->get_record(
            'local_btiportal_programme',
            ['id' => (int)$profile['programmeid']],
            'id,code,name',
            IGNORE_MISSING
        );
        $programmecode = $programme ? trim((string)$programme->code) : '';
        $empty['programme_code'] = $programmecode;

        $courses = [];
        if ($programmecode !== '') {
            $container = $DB->get_record(
                'course',
                ['shortname' => $programmecode],
                'id,shortname,visible',
                IGNORE_MISSING
            );
            if ($container && (int)$container->id > 1 && (int)$container->visible === 1) {
                $courses[(int)$container->id] = self::course_row(
                    self::KIND_PROGRAMME_CONTAINER,
                    (int)$container->id,
                    (string)$container->shortname,
                    $programmecode,
                    (string)($programme->name ?? 'Programme'),
                    $programmecode
                );
            }
        }

        $pathway = student_unit_pathway_helper::get_pathway($userid);
        $empty['currentyear'] = (int)($pathway['currentyear'] ?? 0);
        $empty['currentsemester'] = (int)($pathway['currentsemester'] ?? 0);

        $selected = [];
        try {
            $selected = elective_helper::selected_courseunit_set(
                $userid,
                (int)($profile['academicyearid'] ?? 0),
                (int)($profile['semesterid'] ?? 0)
            );
        } catch (\Throwable $ignored) {
            $selected = [];
        }

        $buckets = [
            'current' => self::KIND_CURRENT_UNIT,
            'studied' => self::KIND_STUDIED_UNIT,
        ];
        foreach ($buckets as $bucket => $kind) {
            foreach ($pathway[$bucket] ?? [] as $unit) {
                self::maybe_add_unit_course($courses, $unit, $kind, $programmecode, $selected, $userid, false);
            }
        }
        foreach ($pathway['upcoming'] ?? [] as $unit) {
            self::maybe_add_unit_course(
                $courses,
                $unit,
                self::KIND_RESIT,
                $programmecode,
                $selected,
                $userid,
                true
            );
        }

        $empty['courses'] = $courses;
        return $empty;
    }

    /**
     * @return list<int>
     */
    public static function entitled_course_ids(int $userid): array {
        return array_map('intval', array_keys(self::for_user($userid)['courses']));
    }

    public static function is_entitled(int $userid, int $courseid): bool {
        return $courseid > 1 && isset(self::for_user($userid)['courses'][$courseid]);
    }

    /**
     * @return array{
     *   kind:string,
     *   entitled:int,
     *   is_programme_container:int,
     *   is_current_semester_unit:int,
     *   programme_code:string,
     *   course_shortname:string,
     *   unitcode:string
     * }
     */
    public static function classify_course(int $userid, int $courseid): array {
        global $DB;
        $entitlement = self::for_user($userid);
        $row = $entitlement['courses'][$courseid] ?? null;
        $course = $courseid > 0
            ? $DB->get_record('course', ['id' => $courseid], 'id,shortname', IGNORE_MISSING)
            : null;
        $kind = $row['kind'] ?? self::KIND_NEITHER;
        return [
            'kind' => $kind,
            'entitled' => $row ? 1 : 0,
            'is_programme_container' => $kind === self::KIND_PROGRAMME_CONTAINER ? 1 : 0,
            'is_current_semester_unit' => $kind === self::KIND_CURRENT_UNIT ? 1 : 0,
            'programme_code' => (string)($entitlement['programme_code'] ?? ''),
            'course_shortname' => $course ? (string)$course->shortname : (string)($row['course_shortname'] ?? ''),
            'unitcode' => (string)($row['unitcode'] ?? ''),
        ];
    }

    /**
     * Whether list_for_courses may include this course for the student.
     */
    public static function can_see_course_assessments(int $userid, int $courseid): bool {
        if ($courseid <= 1 || $userid <= 0) {
            return false;
        }
        if (self::is_entitled($userid, $courseid)) {
            return true;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return is_enrolled($context, $userid, '', true)
            || is_siteadmin($userid)
            || has_capability('moodle/course:view', $context, $userid)
            || has_capability('moodle/grade:viewall', $context, $userid)
            || lecturer_teaching_helper::user_has_active_programme_course_assignment($userid, $courseid);
    }

    /**
     * Programme-container finals only vs all unit assessments.
     */
    public static function list_only_programme_finals(int $userid, int $courseid): bool {
        $kind = self::classify_course($userid, $courseid)['kind'];
        if ($kind === self::KIND_PROGRAMME_CONTAINER) {
            return true;
        }
        return lecturer_teaching_helper::user_has_active_programme_course_assignment($userid, $courseid)
            && $kind === self::KIND_NEITHER;
    }

    /**
     * @param array<int,array> $courses
     * @param array $unit
     * @param array<int,true> $selected
     */
    private static function maybe_add_unit_course(
        array &$courses,
        array $unit,
        string $kind,
        string $programmecode,
        array $selected,
        int $userid,
        bool $onlyifenrolled
    ): void {
        $courseid = (int)($unit['moodlecourseid'] ?? $unit['courseid'] ?? 0);
        if ($courseid <= 1) {
            return;
        }
        $unitid = (int)($unit['courseunitid'] ?? $unit['id'] ?? 0);
        $required = (int)($unit['isrequired'] ?? 1) === 1
            || strtolower((string)($unit['requirementtype'] ?? 'required')) === 'required';
        if (!$required && $unitid > 0 && empty($selected[$unitid])) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context || !is_enrolled($context, $userid, '', true)) {
                return;
            }
            $kind = self::KIND_RESIT;
        }
        if ($onlyifenrolled) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context || !is_enrolled($context, $userid, '', true)) {
                return;
            }
        }
        if (isset($courses[$courseid]) && $courses[$courseid]['kind'] === self::KIND_PROGRAMME_CONTAINER) {
            return;
        }
        if (isset($courses[$courseid]) && $kind === self::KIND_RESIT) {
            return;
        }
        $shortname = (string)($unit['moodlecourseshortname'] ?? '');
        if ($shortname === '') {
            global $DB;
            $shortname = (string)$DB->get_field('course', 'shortname', ['id' => $courseid]);
        }
        $courses[$courseid] = self::course_row(
            $kind,
            $courseid,
            $shortname,
            (string)($unit['code'] ?? ''),
            (string)($unit['name'] ?? ''),
            $programmecode
        );
    }

    /**
     * @return array{kind:string,courseid:int,course_shortname:string,unitcode:string,unitname:string,programme_code:string}
     */
    private static function course_row(
        string $kind,
        int $courseid,
        string $shortname,
        string $unitcode,
        string $unitname,
        string $programmecode
    ): array {
        return [
            'kind' => $kind,
            'courseid' => $courseid,
            'course_shortname' => $shortname,
            'unitcode' => $unitcode,
            'unitname' => $unitname,
            'programme_code' => $programmecode,
        ];
    }
}
