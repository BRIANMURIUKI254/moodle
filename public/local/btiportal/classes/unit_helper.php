<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for persisting portal unit metadata in Moodle DB.
 */
class unit_helper {
    /**
     * Ensure a portal unit teacher mapping exists.
     *
     * @return array{courseid:int, sectionid:int, teacheruserid:int, created:bool}
     */
    public static function ensure_unit_teacher(int $courseid, int $sectionid, int $teacheruserid): array {
        global $DB;

        // Validate basic existence to avoid creating dangling mappings.
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            throw new \moodle_exception('invalidcourseid', 'error', '', $courseid);
        }
        if (!$DB->record_exists('user', ['id' => $teacheruserid, 'deleted' => 0])) {
            throw new \moodle_exception('invaliduserid', 'error', '', $teacheruserid);
        }
        if (!$DB->record_exists('course_sections', ['id' => $sectionid, 'course' => $courseid])) {
            throw new \moodle_exception('invalidsectionid', 'error', '', $sectionid);
        }

        $existing = $DB->get_record('local_btiportal_unit', [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ], '*', IGNORE_MULTIPLE);

        $created = false;
        if ($existing) {
            if ((int)$existing->teacheruserid !== $teacheruserid) {
                $existing->teacheruserid = $teacheruserid;
                $DB->update_record('local_btiportal_unit', $existing);
            }
        } else {
            $DB->insert_record('local_btiportal_unit', (object)[
                'courseid' => $courseid,
                'sectionid' => $sectionid,
                'teacheruserid' => $teacheruserid,
                'timecreated' => time(),
            ]);
            $created = true;
        }

        return [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'teacheruserid' => $teacheruserid,
            'created' => $created,
        ];
    }

    /**
     * Teachers mapped to Moodle topics in a course.
     *
     * Merges legacy local_btiportal_unit rows with active academic Course Unit
     * teacher assignments (same sources as get_my_teaching_sections).
     *
     * @return list<array{courseid:int, sectionid:int, teacheruserid:int, teachername:string, teacheremail:string}>
     */
    public static function get_unit_teachers(int $courseid): array {
        global $DB;

        $bysection = [];

        // First SELECT column must be unique (Moodle indexes get_records_sql by it).
        $sql = "SELECT u.sectionid, u.courseid, u.teacheruserid,
                       usr.firstname, usr.lastname, usr.email
                  FROM {local_btiportal_unit} u
                  JOIN {user} usr ON usr.id = u.teacheruserid AND usr.deleted = 0
                 WHERE u.courseid = ?
              ORDER BY u.sectionid ASC";

        foreach ($DB->get_records_sql($sql, [$courseid]) as $row) {
            $sectionid = (int)$row->sectionid;
            $name = trim($row->firstname . ' ' . $row->lastname);
            $bysection[$sectionid] = [
                'courseid' => (int)$row->courseid,
                'sectionid' => $sectionid,
                'teacheruserid' => (int)$row->teacheruserid,
                'teachername' => $name !== '' ? $name : 'Teacher',
                'teacheremail' => (string)$row->email,
            ];
        }

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_btiportal_course_unit_teacher')
                && $dbman->table_exists('local_btiportal_course_unit')) {
            $academicsql = "SELECT cut.id, cu.courseid, cu.sectionid, cut.userid AS teacheruserid,
                                   usr.firstname, usr.lastname, usr.email, cut.isprimary
                              FROM {local_btiportal_course_unit_teacher} cut
                              JOIN {local_btiportal_course_unit} cu ON cu.id = cut.courseunitid
                              JOIN {user} usr ON usr.id = cut.userid AND usr.deleted = 0
                              JOIN {course_sections} cs ON cs.id = cu.sectionid AND cs.course = cu.courseid
                             WHERE cu.courseid = ?
                               AND cut.status = 'active'
                               AND cu.sectionid > 0
                               AND cs.section > 0
                          ORDER BY cut.isprimary DESC, cut.id ASC";
            foreach ($DB->get_records_sql($academicsql, [$courseid]) as $row) {
                $sectionid = (int)$row->sectionid;
                $isprimary = !empty($row->isprimary);
                // Non-primary academic rows only fill gaps; primary overwrites legacy.
                if (isset($bysection[$sectionid]) && !$isprimary) {
                    continue;
                }
                $name = trim($row->firstname . ' ' . $row->lastname);
                $bysection[$sectionid] = [
                    'courseid' => (int)$row->courseid,
                    'sectionid' => $sectionid,
                    'teacheruserid' => (int)$row->teacheruserid,
                    'teachername' => $name !== '' ? $name : 'Teacher',
                    'teacheremail' => (string)$row->email,
                ];
            }
        }

        ksort($bysection);
        return array_values($bysection);
    }

    /**
     * Sections the current user is assigned to teach (any course).
     *
     * Merges legacy local_btiportal_unit rows with active academic Course Unit
     * teacher assignments that are mapped to a real Moodle topic section
     * (section number > 0 — never General).
     *
     * @return list<array{courseid:int, sectionid:int, teacheruserid:int}>
     */
    public static function get_my_teaching_sections(int $userid): array {
        global $DB;

        $bykey = [];

        $rows = $DB->get_records('local_btiportal_unit', ['teacheruserid' => $userid], 'courseid ASC, sectionid ASC');
        foreach ($rows as $row) {
            $courseid = (int)$row->courseid;
            $sectionid = (int)$row->sectionid;
            $bykey[$courseid . ':' . $sectionid] = [
                'courseid' => $courseid,
                'sectionid' => $sectionid,
                'teacheruserid' => (int)$row->teacheruserid,
            ];
        }

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_btiportal_course_unit_teacher')
                && $dbman->table_exists('local_btiportal_course_unit')) {
            $sql = "SELECT cu.courseid, cu.sectionid, cut.userid AS teacheruserid
                      FROM {local_btiportal_course_unit_teacher} cut
                      JOIN {local_btiportal_course_unit} cu ON cu.id = cut.courseunitid
                      JOIN {course_sections} cs ON cs.id = cu.sectionid AND cs.course = cu.courseid
                     WHERE cut.userid = ?
                       AND cut.status = 'active'
                       AND cu.courseid > 1
                       AND cu.sectionid > 0
                       AND cs.section > 0
                  ORDER BY cu.courseid ASC, cu.sectionid ASC";
            foreach ($DB->get_records_sql($sql, [$userid]) as $row) {
                $courseid = (int)$row->courseid;
                $sectionid = (int)$row->sectionid;
                $key = $courseid . ':' . $sectionid;
                if (!isset($bykey[$key])) {
                    $bykey[$key] = [
                        'courseid' => $courseid,
                        'sectionid' => $sectionid,
                        'teacheruserid' => (int)$row->teacheruserid,
                    ];
                }
            }
        }

        return array_values($bykey);
    }

    /**
     * Remove teacher mapping only — Moodle section content stays intact for reassignment.
     *
     * @return array{courseid:int, sectionid:int, removed:bool}
     */
    public static function remove_unit_teacher(int $courseid, int $sectionid): array {
        global $DB;

        if (!$DB->record_exists('course_sections', ['id' => $sectionid, 'course' => $courseid])) {
            throw new \moodle_exception('invalidsectionid', 'error', '', $sectionid);
        }

        $removed = (bool)$DB->delete_records('local_btiportal_unit', [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ]);

        return [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'removed' => $removed,
        ];
    }

    /**
     * Create a new topics section used as a portal unit.
     * Reuses an existing non-General topic with the same name when present
     * (prevents duplicate empty "Life Skills"-style sections).
     *
     * @return array{courseid:int, sectionid:int, section:int, name:string}
     */
    public static function create_course_unit(int $courseid, string $name): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $name = trim($name);
        if ($name === '') {
            throw new \invalid_parameter_exception('Unit name is required');
        }

        $course = get_course($courseid);

        // Prefer an existing topic with the same name (case-insensitive).
        $existing = $DB->get_records_sql(
            "SELECT *
               FROM {course_sections}
              WHERE course = ?
                AND section > 0
                AND " . $DB->sql_compare_text('name') . " <> ''
           ORDER BY section ASC",
            [$courseid]
        );
        $norm = \core_text::strtolower($name);
        $best = null;
        $bestmodcount = -1;
        foreach ($existing as $candidate) {
            $candidatename = trim((string)$candidate->name);
            if ($candidatename === '') {
                continue;
            }
            if (\core_text::strtolower($candidatename) !== $norm) {
                continue;
            }
            $modcount = (int)$DB->count_records('course_modules', [
                'course' => $courseid,
                'section' => (int)$candidate->id,
                'deletioninprogress' => 0,
            ]);
            if ($modcount > $bestmodcount) {
                $best = $candidate;
                $bestmodcount = $modcount;
            }
        }
        if ($best) {
            return [
                'courseid' => $courseid,
                'sectionid' => (int)$best->id,
                'section' => (int)$best->section,
                'name' => trim((string)$best->name) !== '' ? trim((string)$best->name) : $name,
            ];
        }

        $maxsection = (int)$DB->get_field_sql(
            "SELECT COALESCE(MAX(section), 0) FROM {course_sections} WHERE course = ?",
            [$courseid]
        );
        $next = $maxsection + 1;

        course_create_sections_if_missing($course, $next);
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $next], '*', MUST_EXIST);
        course_update_section($course, $section, ['name' => $name]);

        // Refresh after rename.
        $section = $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);

        return [
            'courseid' => $courseid,
            'sectionid' => (int)$section->id,
            'section' => (int)$section->section,
            'name' => $name,
        ];
    }

    /**
     * Delete portal mapping and remove the Moodle section (section 0 is never deleted).
     *
     * Uses a resilient path: Moodle's course_delete_section can throw
     * "Can't find data record in database" when a module in the section has
     * stale/orphan references. We clear mapping first, try core delete, then
     * fall back to per-module cleanup + direct section removal.
     *
     * @return array{courseid:int, sectionid:int, deleted:bool}
     */
    public static function delete_course_unit(int $courseid, int $sectionid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        // course_delete_module lives in course/lib.php (already required above).

        // Soft-archive academic Course Unit rows for this section (additive; does not change return shape).
        try {
            academic_course_unit_helper::archive_by_section($courseid, $sectionid);
        } catch (\Throwable $e) {
            debugging('local_btiportal archive academic units on section delete: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Always clear portal mapping first so UI stays consistent even if Moodle section is gone.
        $DB->delete_records('local_btiportal_unit', [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ]);

        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid]);
        if (!$section) {
            return [
                'courseid' => $courseid,
                'sectionid' => $sectionid,
                'deleted' => true,
            ];
        }

        if ((int)$section->section <= 0) {
            throw new \moodle_exception('error', 'error', '', null, 'The General section cannot be deleted.');
        }

        $course = get_course($courseid);
        $sectionnum = (int)$section->section;

        // Prefer core deletion when it works (handles renumbering / format options).
        try {
            rebuild_course_cache($courseid, true);
            $ok = course_delete_section($course, $sectionnum, true);
            if ($ok) {
                rebuild_course_cache($courseid, true);
                return [
                    'courseid' => $courseid,
                    'sectionid' => $sectionid,
                    'deleted' => true,
                ];
            }
        } catch (\Throwable $e) {
            // Fall through to manual cleanup.
            debugging('local_btiportal delete_course_unit core path failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Manual resilient cleanup — delete modules one by one, then remove the section row.
        self::delete_section_modules_best_effort($courseid, $section);
        self::remove_section_record_and_renumber($courseid, $section);

        rebuild_course_cache($courseid, true);

        return [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'deleted' => true,
        ];
    }

    /**
     * Best-effort deletion of every course module listed on a section.
     */
    private static function delete_section_modules_best_effort(int $courseid, \stdClass $section): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $cmids = [];
        if (!empty($section->sequence)) {
            foreach (preg_split('/,/', $section->sequence, -1, PREG_SPLIT_NO_EMPTY) as $cmid) {
                $cmids[] = (int)$cmid;
            }
        }
        // Also catch modules that still point at this section but are missing from sequence.
        $extra = $DB->get_records('course_modules', ['course' => $courseid, 'section' => $section->id], '', 'id');
        foreach ($extra as $row) {
            $cmids[] = (int)$row->id;
        }
        $cmids = array_values(array_unique(array_filter($cmids)));

        foreach ($cmids as $cmid) {
            try {
                course_delete_module($cmid, true);
            } catch (\Throwable $e) {
                // Last resort: detach / remove the CM row so the section can be deleted.
                try {
                    $DB->delete_records('course_modules', ['id' => $cmid, 'course' => $courseid]);
                } catch (\Throwable $ignored) {
                    // Continue deleting remaining modules.
                }
            }
        }

        $DB->set_field('course_sections', 'sequence', '', ['id' => $section->id]);
    }

    /**
     * Remove a section row and renumber higher sections downward.
     */
    private static function remove_section_record_and_renumber(int $courseid, \stdClass $section): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $sectionnum = (int)$section->section;
        $sectionid = (int)$section->id;

        $DB->delete_records('course_format_options', ['sectionid' => $sectionid]);
        $DB->delete_records('course_sections', ['id' => $sectionid, 'course' => $courseid]);

        // Delete section summary files if any.
        try {
            $context = \context_course::instance($courseid);
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'course', 'section', $sectionid);
        } catch (\Throwable $ignored) {
            // Non-fatal.
        }

        // Renumber sections that sat above the deleted one.
        $higher = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > ?',
            [$courseid, $sectionnum],
            'section ASC'
        );
        foreach ($higher as $row) {
            $DB->set_field('course_sections', 'section', ((int)$row->section) - 1, ['id' => $row->id]);
        }

        // Keep topics format numsections in sync when present.
        $course = get_course($courseid);
        $format = course_get_format($course);
        $options = $format->get_format_options();
        if (array_key_exists('numsections', $options) && (int)$options['numsections'] > 0) {
            $format->update_course_format_options(['numsections' => (int)$options['numsections'] - 1]);
        }
    }
}

