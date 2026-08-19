<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Moodle mapping diagnostics for academic course units.
 * Does not delete records.
 */
class moodle_mapping_diagnostics_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function diagnose(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_btiportal_course_unit')) {
            return ['ok' => false, 'errors' => [['field' => '', 'message' => 'Course unit table is missing']]];
        }

        $units = $DB->get_records('local_btiportal_course_unit', null, 'code ASC, id ASC');
        $issues = [];
        $counts = [
            'units' => count($units),
            'connected' => 0,
            'not_connected' => 0,
            'missing_moodle_course' => 0,
            'unpublished_moodle_course' => 0,
            'duplicate_unit_codes' => 0,
            'duplicate_moodle_shortnames' => 0,
            'shortname_mismatch' => 0,
        ];

        $codes = [];
        $shortnames = [];
        $linkedcourseids = [];

        foreach ($units as $unit) {
            $formatted = academic_course_unit_helper::format($unit);
            $status = (string)$formatted['mappingstatus'];
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
            $code = strtoupper(trim((string)$unit->code));
            $codes[$code][] = (int)$unit->id;
            $courseid = (int)$unit->courseid;
            if ($courseid > 1) {
                $linkedcourseids[$courseid] = $courseid;
                $shortname = strtoupper(trim((string)$formatted['moodlecourseshortname']));
                if ($shortname !== '') {
                    $shortnames[$shortname][] = (int)$unit->id;
                }
            }

            if ($status === 'not_connected') {
                $issues[] = self::issue($unit, $formatted, 'not_connected', 'Course unit is not linked to a Moodle course');
            } else if ($status === 'missing_moodle_course') {
                $issues[] = self::issue($unit, $formatted, 'missing_moodle_course', 'Stored Moodle course id does not exist');
            } else if ($status === 'unpublished_moodle_course') {
                $issues[] = self::issue($unit, $formatted, 'unpublished_moodle_course', 'Linked Moodle course is hidden');
            } else if ($courseid > 1 && $code !== '' && strtoupper((string)$formatted['moodlecourseshortname']) !== $code) {
                $counts['shortname_mismatch']++;
                $issues[] = self::issue(
                    $unit,
                    $formatted,
                    'shortname_mismatch',
                    'Moodle shortname does not match the unit code'
                );
            }
        }

        foreach ($codes as $code => $ids) {
            if ($code !== '' && count($ids) > 1) {
                $counts['duplicate_unit_codes']++;
                foreach ($ids as $id) {
                    if (isset($units[$id])) {
                        $formatted = academic_course_unit_helper::format($units[$id]);
                        $issues[] = self::issue($units[$id], $formatted, 'duplicate_unit_code', 'Unit code is used more than once');
                    }
                }
            }
        }
        foreach ($shortnames as $shortname => $ids) {
            $uniqueunits = array_values(array_unique($ids));
            if ($shortname !== '' && count($uniqueunits) > 1) {
                $counts['duplicate_moodle_shortnames']++;
                foreach ($uniqueunits as $id) {
                    if (isset($units[$id])) {
                        $formatted = academic_course_unit_helper::format($units[$id]);
                        $issues[] = self::issue($units[$id], $formatted, 'duplicate_moodle_shortname', 'Multiple units share this Moodle shortname');
                    }
                }
            }
        }

        $orphanmoodle = [];
        if ($DB->get_manager()->table_exists('local_btiportal_programme')) {
            $programmes = $DB->get_records('local_btiportal_programme', null, 'code ASC', 'id,code,name');
            foreach ($programmes as $programme) {
                $course = $DB->get_record('course', ['shortname' => (string)$programme->code], 'id,shortname,fullname,visible');
                if ($course && !isset($linkedcourseids[(int)$course->id])) {
                    // Programme container courses are expected and are not unit mappings.
                    continue;
                }
            }
        }

        $moodlecourses = $DB->get_records_select(
            'course',
            'id > 1',
            [],
            'shortname ASC',
            'id,shortname,fullname,visible,category',
            0,
            500
        );
        foreach ($moodlecourses as $course) {
            if (isset($linkedcourseids[(int)$course->id])) {
                continue;
            }
            $idnumber = (string)($course->idnumber ?? '');
            $shortname = (string)$course->shortname;
            $matchesunit = $DB->record_exists('local_btiportal_course_unit', ['code' => $shortname])
                || ($idnumber !== '' && $DB->record_exists('local_btiportal_course_unit', ['code' => $idnumber]));
            $isprogramme = $DB->record_exists('local_btiportal_programme', ['code' => $shortname]);
            if ($matchesunit && !$isprogramme) {
                $orphanmoodle[] = [
                    'courseid' => (int)$course->id,
                    'shortname' => $shortname,
                    'fullname' => (string)$course->fullname,
                    'visible' => (int)$course->visible,
                    'reason' => 'Moodle course shortname matches a unit code but is not linked',
                ];
            }
        }

        return [
            'ok' => true,
            'record' => [
                'counts' => $counts,
                'issues' => array_values($issues),
                'unlinkedmoodlecourses' => $orphanmoodle,
            ],
        ];
    }

    /**
     * Exact Moodle course matches for a unit code via shortname and/or idnumber.
     * Does not match on fullname. Never creates or deletes courses.
     *
     * @return list<array{courseid:int,shortname:string,fullname:string,visible:int,matchby:string}>
     */
    public static function find_matching_moodle_courses(string $code): array {
        global $DB;

        $code = trim($code);
        if ($code === '') {
            return [];
        }

        $found = [];
        $byshortname = $DB->get_records('course', ['shortname' => $code], 'id ASC', 'id,shortname,fullname,visible,idnumber');
        foreach ($byshortname as $course) {
            $found[(int)$course->id] = self::match_row($course, 'shortname');
        }
        $byidnumber = $DB->get_records('course', ['idnumber' => $code], 'id ASC', 'id,shortname,fullname,visible,idnumber');
        foreach ($byidnumber as $course) {
            $id = (int)$course->id;
            if (isset($found[$id])) {
                $found[$id]['matchby'] = 'shortname_idnumber';
            } else {
                $found[$id] = self::match_row($course, 'idnumber');
            }
        }

        $rows = array_values($found);
        usort($rows, static function (array $a, array $b): int {
            $apref = str_starts_with((string)$a['matchby'], 'shortname') ? 0 : 1;
            $bpref = str_starts_with((string)$b['matchby'], 'shortname') ? 0 : 1;
            if ($apref !== $bpref) {
                return $apref <=> $bpref;
            }
            return (int)$a['courseid'] <=> (int)$b['courseid'];
        });
        return $rows;
    }

    /**
     * Look up Moodle courses that match this unit code. Does not write.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function preview_unit(int $courseunitid): array {
        $unit = self::require_unit($courseunitid);
        if (!$unit) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course unit not found']]];
        }

        $formatted = academic_course_unit_helper::format($unit);
        $currentid = (int)$unit->courseid;
        $matches = self::find_matching_moodle_courses((string)$unit->code);
        $matchcount = count($matches);
        $recommended = $matches[0] ?? null;
        $matchid = $recommended ? (int)$recommended['courseid'] : 0;
        $needsselection = $matchcount > 1 ? 1 : 0;
        $wouldremap = ($currentid > 1 && $matchid > 1 && $currentid !== $matchid) ? 1 : 0;
        $wouldreuse = ($matchid > 1) ? 1 : 0;
        $wouldcreate = ($matchcount === 0) ? 1 : 0;

        if ($needsselection) {
            $message = 'Multiple Moodle courses match this unit code. Select one. No course will be created or deleted.';
        } else if ($wouldremap) {
            $message = 'The local Course Unit mapping will change from '
                . (string)($formatted['moodlecourseshortname'] ?? '') . ' (ID ' . $currentid . ') to '
                . (string)$recommended['shortname'] . ' (ID ' . $matchid
                . '). The existing Moodle course will be reused. No Moodle course will be deleted.';
        } else if ($wouldreuse && $currentid === $matchid) {
            $message = 'The unit is already linked to the matching Moodle course. Re-sync will verify the mapping.';
        } else if ($wouldreuse) {
            $message = 'An existing Moodle course matches this unit code and will be reused. No duplicate will be created.';
        } else {
            $message = 'No Moodle course uses this unit code as shortname or idnumber. Re-sync can create one after confirmation.';
        }

        return [
            'ok' => true,
            'record' => [
                'unitid' => (int)$unit->id,
                'unitcode' => (string)$unit->code,
                'unitname' => (string)$unit->name,
                'currentcourseid' => $currentid > 1 ? $currentid : 0,
                'currentshortname' => (string)($formatted['moodlecourseshortname'] ?? ''),
                'currentfullname' => (string)($formatted['moodlecoursefullname'] ?? ''),
                'matchcourseid' => $matchid,
                'matchshortname' => $recommended ? (string)$recommended['shortname'] : '',
                'matchfullname' => $recommended ? (string)$recommended['fullname'] : '',
                'matchvisible' => $recommended ? (int)$recommended['visible'] : 0,
                'matchcount' => $matchcount,
                'needsselection' => $needsselection,
                'wouldremap' => $wouldremap,
                'wouldreuse' => $wouldreuse,
                'wouldcreate' => $wouldcreate,
                'message' => $message,
                'matches' => $matches,
            ],
        ];
    }

    /**
     * Link a unit to an existing Moodle course (preferred) or create one if none match.
     * Never deletes Moodle courses. Never silently remaps. Never duplicates an existing shortname.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function sync_unit(
        int $courseunitid,
        int $userid,
        bool $confirmremap = false,
        int $targetcourseid = 0
    ): array {
        global $DB;

        $unit = self::require_unit($courseunitid);
        if (!$unit) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course unit not found']]];
        }

        $currentid = (int)$unit->courseid;
        $matches = self::find_matching_moodle_courses((string)$unit->code);
        $matchids = [];
        foreach ($matches as $match) {
            $matchids[(int)$match['courseid']] = $match;
        }

        if (count($matches) > 1 && $targetcourseid <= 1) {
            return ['ok' => false, 'errors' => [[
                'field' => 'targetcourseid',
                'message' => 'Multiple Moodle courses match this unit code. Select one before saving.',
            ]]];
        }
        if ($targetcourseid > 1 && !isset($matchids[$targetcourseid])) {
            return ['ok' => false, 'errors' => [[
                'field' => 'targetcourseid',
                'message' => 'The selected Moodle course does not match this unit code by shortname or idnumber',
            ]]];
        }

        $courseid = count($matches) > 0
            ? ($targetcourseid > 1 ? $targetcourseid : (int)$matches[0]['courseid'])
            : 0;

        if ($currentid > 1 && ($courseid !== $currentid) && !$confirmremap) {
            $proposed = $courseid > 1 ? (string)$courseid : 'a newly created Moodle course';
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'confirmremap',
                    'message' => 'Confirmation is required before changing the Moodle mapping from ID '
                        . $currentid . ' to ' . $proposed,
                ]],
            ];
        }

        $sectionid = 0;
        if (count($matches) > 0) {
            try {
                $synced = moodle_catalogue_sync_helper::ensure_unit_section($courseid, (string)$unit->name);
                $sectionid = (int)$synced['sectionid'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'errors' => [['field' => 'sectionid', 'message' => $e->getMessage()]]];
            }
        } else {
            try {
                $synced = moodle_catalogue_sync_helper::ensure_unit_moodle_course_and_section(
                    (int)$unit->programmeid,
                    (string)$unit->code,
                    (string)$unit->name,
                    (string)($unit->description ?? '')
                );
                $courseid = (int)$synced['courseid'];
                $sectionid = (int)$synced['sectionid'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => $e->getMessage()]]];
            }
        }

        if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Moodle course was not created']]];
        }

        $updated = academic_course_unit_helper::update_moodle_mapping(
            $courseunitid,
            $courseid,
            $sectionid,
            $userid
        );
        if (empty($updated['ok'])) {
            return $updated;
        }

        self::stamp_verified($courseunitid);
        $verify = self::verify_unit($courseunitid);
        if (empty($verify['ok'])) {
            return [
                'ok' => false,
                'errors' => $verify['errors'] ?? [['field' => 'courseid', 'message' => 'Moodle course could not be verified after save']],
                'record' => $updated['record'] ?? null,
            ];
        }

        return $verify;
    }

    /**
     * Verify the stored Moodle course still exists. Does not create records.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function verify_unit(int $courseunitid): array {
        global $DB;
        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        if (!$unit) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course unit not found']]];
        }
        $formatted = academic_course_unit_helper::format($unit);
        if ($formatted['mappingstatus'] === 'connected' || $formatted['mappingstatus'] === 'unpublished_moodle_course') {
            self::stamp_verified($courseunitid);
            $fresh = self::require_unit($courseunitid);
            return ['ok' => true, 'record' => academic_course_unit_helper::format($fresh ?: $unit)];
        }
        $message = $formatted['mappingstatus'] === 'missing_moodle_course'
            ? 'The stored Moodle course id is invalid'
            : 'This course unit is not connected to Moodle';
        return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => $message]], 'record' => $formatted];
    }

    private static function issue(\stdClass $unit, array $formatted, string $kind, string $message): array {
        $matches = self::find_matching_moodle_courses((string)$unit->code);
        $recommended = $matches[0] ?? null;
        return [
            'courseunitid' => (int)$unit->id,
            'code' => (string)$unit->code,
            'name' => (string)$unit->name,
            'courseid' => (int)$unit->courseid,
            'moodlecourseshortname' => (string)($formatted['moodlecourseshortname'] ?? ''),
            'moodlecoursefullname' => (string)($formatted['moodlecoursefullname'] ?? ''),
            'recommendedcourseid' => $recommended ? (int)$recommended['courseid'] : 0,
            'recommendedshortname' => $recommended ? (string)$recommended['shortname'] : '',
            'recommendedfullname' => $recommended ? (string)$recommended['fullname'] : '',
            'matchcount' => count($matches),
            'mappingstatus' => (string)$formatted['mappingstatus'],
            'kind' => $kind,
            'message' => $message,
            'unitstatus' => (string)$unit->status,
        ];
    }

    private static function match_row(\stdClass $course, string $matchby): array {
        return [
            'courseid' => (int)$course->id,
            'shortname' => (string)$course->shortname,
            'fullname' => (string)$course->fullname,
            'visible' => (int)$course->visible,
            'matchby' => $matchby,
        ];
    }

    private static function require_unit(int $courseunitid): ?\stdClass {
        global $DB;
        $unit = $DB->get_record('local_btiportal_course_unit', ['id' => $courseunitid]);
        return $unit ?: null;
    }

    private static function stamp_verified(int $courseunitid): void {
        global $DB;
        if (!$DB->get_manager()->field_exists('local_btiportal_course_unit', 'moodleverifiedat')) {
            return;
        }
        $DB->set_field('local_btiportal_course_unit', 'moodleverifiedat', time(), ['id' => $courseunitid]);
    }

    public static function preview_structure_keys(): array {
        return [
            'unitid' => new \core_external\external_value(PARAM_INT, 'Course unit id'),
            'unitcode' => new \core_external\external_value(PARAM_TEXT, 'Unit code'),
            'unitname' => new \core_external\external_value(PARAM_TEXT, 'Unit name'),
            'currentcourseid' => new \core_external\external_value(PARAM_INT, 'Currently linked Moodle course id'),
            'currentshortname' => new \core_external\external_value(PARAM_TEXT, 'Current Moodle shortname'),
            'currentfullname' => new \core_external\external_value(PARAM_TEXT, 'Current Moodle fullname'),
            'matchcourseid' => new \core_external\external_value(PARAM_INT, 'Matching Moodle course id'),
            'matchshortname' => new \core_external\external_value(PARAM_TEXT, 'Matching Moodle shortname'),
            'matchfullname' => new \core_external\external_value(PARAM_TEXT, 'Matching Moodle fullname'),
            'matchvisible' => new \core_external\external_value(PARAM_INT, 'Matching Moodle visibility'),
            'wouldremap' => new \core_external\external_value(PARAM_INT, '1 when the linked course id would change'),
            'wouldreuse' => new \core_external\external_value(PARAM_INT, '1 when an existing Moodle course will be reused'),
            'wouldcreate' => new \core_external\external_value(PARAM_INT, '1 when a new Moodle course would be created'),
            'matchcount' => new \core_external\external_value(PARAM_INT, 'Number of exact Moodle matches'),
            'needsselection' => new \core_external\external_value(PARAM_INT, '1 when the administrator must choose among matches'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'Human-readable preview'),
            'matches' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'courseid' => new \core_external\external_value(PARAM_INT, 'Moodle course id'),
                    'shortname' => new \core_external\external_value(PARAM_TEXT, 'shortname'),
                    'fullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
                    'visible' => new \core_external\external_value(PARAM_INT, 'visible'),
                    'matchby' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'shortname|idnumber|shortname_idnumber'),
                ])
            ),
        ];
    }

    public static function diagnose_structure_keys(): array {
        return [
            'counts' => new \core_external\external_single_structure([
                'units' => new \core_external\external_value(PARAM_INT, 'units'),
                'connected' => new \core_external\external_value(PARAM_INT, 'connected'),
                'not_connected' => new \core_external\external_value(PARAM_INT, 'not_connected'),
                'missing_moodle_course' => new \core_external\external_value(PARAM_INT, 'missing'),
                'unpublished_moodle_course' => new \core_external\external_value(PARAM_INT, 'unpublished'),
                'duplicate_unit_codes' => new \core_external\external_value(PARAM_INT, 'duplicate codes'),
                'duplicate_moodle_shortnames' => new \core_external\external_value(PARAM_INT, 'duplicate shortnames'),
                'shortname_mismatch' => new \core_external\external_value(PARAM_INT, 'shortname mismatches'),
            ]),
            'issues' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'courseunitid' => new \core_external\external_value(PARAM_INT, 'id'),
                    'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'courseid' => new \core_external\external_value(PARAM_INT, 'courseid'),
                    'moodlecourseshortname' => new \core_external\external_value(PARAM_TEXT, 'shortname'),
                    'moodlecoursefullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
                    'recommendedcourseid' => new \core_external\external_value(PARAM_INT, 'recommended Moodle id'),
                    'recommendedshortname' => new \core_external\external_value(PARAM_TEXT, 'recommended shortname'),
                    'recommendedfullname' => new \core_external\external_value(PARAM_TEXT, 'recommended fullname'),
                    'matchcount' => new \core_external\external_value(PARAM_INT, 'exact match count'),
                    'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
                    'kind' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'kind'),
                    'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
                    'unitstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'unit status'),
                ])
            ),
            'unlinkedmoodlecourses' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'courseid' => new \core_external\external_value(PARAM_INT, 'courseid'),
                    'shortname' => new \core_external\external_value(PARAM_TEXT, 'shortname'),
                    'fullname' => new \core_external\external_value(PARAM_TEXT, 'fullname'),
                    'visible' => new \core_external\external_value(PARAM_INT, 'visible'),
                    'reason' => new \core_external\external_value(PARAM_TEXT, 'reason'),
                ])
            ),
        ];
    }
}
