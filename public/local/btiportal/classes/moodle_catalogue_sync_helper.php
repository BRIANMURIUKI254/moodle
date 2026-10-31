<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Provision Moodle categories / courses / sections for academic catalogue records.
 */
class moodle_catalogue_sync_helper {

    /**
     * Ensure a top-level (or parented) Moodle category exists for a programme.
     * Reuses by idnumber = programme code when present.
     */
    public static function ensure_programme_category(string $code, string $name, int $parentid = 0): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $code = trim($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            throw new \invalid_parameter_exception('Programme code and name are required for Moodle category sync');
        }

        $existing = $DB->get_record('course_categories', ['idnumber' => $code]);
        if ($existing) {
            return (int)$existing->id;
        }

        // Prefer a shared "BTI Programmes" parent when creating under root.
        if ($parentid <= 0) {
            $parentid = self::ensure_root_programmes_parent();
        }

        $category = \core_course_category::create([
            'name' => $name,
            'idnumber' => $code,
            'parent' => $parentid,
            'description' => 'Synced from bti LMS programme ' . $code,
            'descriptionformat' => FORMAT_HTML,
            'visible' => 1,
        ]);

        return (int)$category->id;
    }

    /**
     * Parent category that groups programme categories.
     */
    public static function ensure_root_programmes_parent(): int {
        global $DB;

        $idnumber = 'BTI-PROGRAMMES';
        $existing = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
        if ($existing) {
            return (int)$existing->id;
        }

        $byname = $DB->get_record_select(
            'course_categories',
            "parent = 0 AND " . $DB->sql_compare_text('name') . " = " . $DB->sql_compare_text(':n'),
            ['n' => 'BTI Programmes']
        );
        if ($byname) {
            if (trim((string)$byname->idnumber) === '') {
                $byname->idnumber = $idnumber;
                $DB->update_record('course_categories', $byname);
            }
            return (int)$byname->id;
        }

        $category = \core_course_category::create([
            'name' => 'BTI Programmes',
            'idnumber' => $idnumber,
            'parent' => 0,
            'description' => 'Academic programmes synced from bti LMS',
            'descriptionformat' => FORMAT_HTML,
            'visible' => 1,
        ]);

        return (int)$category->id;
    }

    /**
     * Ensure the programme also has a Moodle course container (shortname = programme code)
     * inside its category — matches the seed_moodle_course_containers pattern.
     *
     * @return int Moodle course id
     */
    public static function ensure_programme_course_container(
        int $categoryid,
        string $code,
        string $name,
        string $summary = ''
    ): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $code = trim($code);
        $name = trim($name);
        if ($categoryid <= 0 || $code === '' || $name === '') {
            throw new \invalid_parameter_exception('Category, code and name are required for Moodle course sync');
        }

        $existing = $DB->get_record('course', ['shortname' => $code]);
        if ($existing) {
            // Keep category aligned when possible.
            if ((int)$existing->category !== $categoryid) {
                $existing->category = $categoryid;
                $DB->update_record('course', $existing);
            }
            return (int)$existing->id;
        }

        $created = create_course((object)[
            'fullname' => $name,
            'shortname' => $code,
            'category' => $categoryid,
            'summary' => $summary !== '' ? $summary : ('Moodle course container for ' . $name),
            'summaryformat' => FORMAT_HTML,
            'format' => 'topics',
            'numsections' => 0,
            'visible' => 1,
            'enablecompletion' => 1,
        ]);

        return (int)$created->id;
    }

    /**
     * Create (or reuse) a Moodle course for an academic unit and a named topics section.
     *
     * @return array{courseid:int,sectionid:int,section:int,name:string}
     */
    public static function ensure_unit_moodle_course_and_section(
        int $programmeid,
        string $unitcode,
        string $unitname,
        string $summary = ''
    ): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $unitcode = trim($unitcode);
        $unitname = trim($unitname);
        if ($unitcode === '' || $unitname === '') {
            throw new \invalid_parameter_exception('Unit code and name are required for Moodle sync');
        }

        $programme = $DB->get_record('local_btiportal_programme', ['id' => $programmeid]);
        if (!$programme) {
            throw new \invalid_parameter_exception('Programme not found for Moodle unit sync');
        }

        $categoryid = (int)$programme->moodlecategoryid;
        if ($categoryid <= 0 || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
            $categoryid = self::ensure_programme_category(
                (string)$programme->code,
                (string)$programme->name
            );
            $programme->moodlecategoryid = $categoryid;
            $programme->timemodified = time();
            $DB->update_record('local_btiportal_programme', $programme);
        }

        // Also ensure the programme container course exists (course-level sync).
        self::ensure_programme_course_container(
            $categoryid,
            (string)$programme->code,
            (string)$programme->name,
            (string)($programme->description ?? '')
        );

        $shortname = self::unique_course_shortname($unitcode);
        $existing = $DB->get_record('course', ['shortname' => $shortname]);
        if ($existing) {
            $courseid = (int)$existing->id;
        } else {
            $created = create_course((object)[
                'fullname' => $unitname,
                'shortname' => $shortname,
                'category' => $categoryid,
                'summary' => $summary !== '' ? $summary : ('Synced course unit ' . $unitcode),
                'summaryformat' => FORMAT_HTML,
                'format' => 'topics',
                'numsections' => 1,
                'visible' => 1,
                'enablecompletion' => 1,
                'idnumber' => $unitcode,
            ]);
            $courseid = (int)$created->id;
        }

        return unit_helper::create_course_unit($courseid, $unitname);
    }

    /**
     * When a Moodle course already exists, ensure a topics section for the unit name.
     *
     * @return array{courseid:int,sectionid:int,section:int,name:string}
     */
    public static function ensure_unit_section(int $courseid, string $unitname): array {
        return unit_helper::create_course_unit($courseid, $unitname);
    }

    /**
     * Prefer the unit code; if taken by a different course, append a numeric suffix.
     */
    private static function unique_course_shortname(string $code): string {
        global $DB;

        $base = \core_text::substr(trim($code), 0, 90);
        if ($base === '') {
            $base = 'UNIT';
        }
        if (!$DB->record_exists('course', ['shortname' => $base])) {
            return $base;
        }
        // Same shortname already used — allow reuse (ensure_unit maps section into it).
        return $base;
    }
}
