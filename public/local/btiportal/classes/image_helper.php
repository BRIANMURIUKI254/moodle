<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Persist portal cover images (Moodle course / section, programme, academic course unit).
 */
class image_helper {
    public const AREA_COURSE = 'courseimage';
    public const AREA_UNIT = 'unitimage';
    public const AREA_PROGRAMME = 'programmeimage';
    public const AREA_COURSEUNIT = 'courseunitimage';

    /**
     * @return array{fileurl:string,filename:string,courseid?:int}
     */
    public static function save_from_draft(string $filearea, int $itemid, int $draftitemid, int $courseid = 0): array {
        global $CFG;

        if (!in_array($filearea, [self::AREA_COURSE, self::AREA_UNIT], true)) {
            throw new \invalid_parameter_exception('Invalid image file area');
        }
        if ($draftitemid <= 0 || $itemid <= 0) {
            throw new \invalid_parameter_exception('Missing draft or item id');
        }

        require_once($CFG->libdir . '/filelib.php');
        $context = \context_system::instance();

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_btiportal', $filearea, $itemid);

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'local_btiportal',
            $filearea,
            $itemid,
            [
                'subdirs' => 0,
                'maxfiles' => 1,
                'accepted_types' => ['image'],
            ]
        );

        $files = $fs->get_area_files($context->id, 'local_btiportal', $filearea, $itemid, 'filename', false);
        $stored = reset($files);
        if (!$stored) {
            throw new \moodle_exception('nofile', 'error');
        }

        $url = \moodle_url::make_webservice_pluginfile_url(
            $context->id,
            'local_btiportal',
            $filearea,
            $itemid,
            '/',
            $stored->get_filename()
        );

        return [
            'fileurl' => $url->out(false),
            'filename' => $stored->get_filename(),
            'courseid' => $courseid,
        ];
    }

    /**
     * Public webservice pluginfile URL for a stored portal cover, or empty string.
     */
    public static function get_url(string $filearea, int $itemid): string {
        $allowed = [
            self::AREA_COURSE,
            self::AREA_UNIT,
            self::AREA_PROGRAMME,
            self::AREA_COURSEUNIT,
        ];
        if (!in_array($filearea, $allowed, true) || $itemid <= 0) {
            return '';
        }

        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_btiportal', $filearea, $itemid, 'filename', false);
        $stored = reset($files);
        if (!$stored) {
            return '';
        }

        $url = \moodle_url::make_webservice_pluginfile_url(
            $context->id,
            'local_btiportal',
            $filearea,
            $itemid,
            '/',
            $stored->get_filename()
        );
        return $url->out(false);
    }

    /**
     * Course cover + unit covers for one Moodle course.
     *
     * Prefers dedicated portal course/unit covers; falls back to programme /
     * academic course-unit catalogue images when those were uploaded instead.
     *
     * @return array{courseid:int,courseimageurl:string,units:array<int,array{sectionid:int,fileurl:string}>}
     */
    public static function get_for_course(int $courseid): array {
        global $DB;

        $courseid = (int)$courseid;
        $units = [];
        $courseimageurl = $courseid > 0 ? self::get_url(self::AREA_COURSE, $courseid) : '';

        $hasunits = $DB->get_manager()->table_exists('local_btiportal_course_unit');
        $hasprogramme = $DB->get_manager()->table_exists('local_btiportal_programme');

        if ($courseimageurl === '' && $courseid > 0 && $hasprogramme) {
            $progid = 0;
            if ($hasunits) {
                $progid = (int)$DB->get_field_sql(
                    "SELECT programmeid
                       FROM {local_btiportal_course_unit}
                      WHERE courseid = ? AND programmeid > 0
                   ORDER BY id ASC",
                    [$courseid],
                    IGNORE_MULTIPLE
                );
            }
            if ($progid <= 0) {
                $shortname = (string)$DB->get_field('course', 'shortname', ['id' => $courseid]);
                if ($shortname !== '') {
                    $progid = (int)$DB->get_field('local_btiportal_programme', 'id', ['code' => $shortname]);
                }
            }
            if ($progid > 0) {
                $courseimageurl = (string)$DB->get_field('local_btiportal_programme', 'imageurl', ['id' => $progid]);
                if ($courseimageurl === '') {
                    $courseimageurl = self::get_url(self::AREA_PROGRAMME, $progid);
                }
            }
        }

        if ($courseid > 0) {
            $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id');
            foreach ($sections as $sec) {
                $sectionid = (int)$sec->id;
                $fileurl = self::get_url(self::AREA_UNIT, $sectionid);
                if ($fileurl === '' && $hasunits) {
                    $acu = $DB->get_record('local_btiportal_course_unit', [
                        'courseid' => $courseid,
                        'sectionid' => $sectionid,
                    ], 'id,imageurl', IGNORE_MISSING);
                    if ($acu) {
                        $fileurl = trim((string)($acu->imageurl ?? ''));
                        if ($fileurl === '') {
                            $fileurl = self::get_url(self::AREA_COURSEUNIT, (int)$acu->id);
                        }
                    }
                }
                if ($fileurl !== '') {
                    $units[] = [
                        'sectionid' => $sectionid,
                        'fileurl' => $fileurl,
                    ];
                }
            }
        }

        return [
            'courseid' => $courseid,
            'courseimageurl' => $courseimageurl,
            'units' => $units,
        ];
    }

    /**
     * @param int[] $courseids
     * @return array{courses:array<int,array{courseid:int,courseimageurl:string,units:array}>}
     */
    public static function get_for_courses(array $courseids): array {
        $out = [];
        $seen = [];
        foreach ($courseids as $courseid) {
            $courseid = (int)$courseid;
            if ($courseid <= 0 || isset($seen[$courseid])) {
                continue;
            }
            $seen[$courseid] = true;
            $out[] = self::get_for_course($courseid);
        }
        return ['courses' => $out];
    }

    /**
     * Save programme / academic course-unit catalogue cover and persist imageurl on the row.
     *
     * @return array{fileurl:string,filename:string,itemid:int,filearea:string}
     */
    public static function save_catalogue_image(string $filearea, int $itemid, int $draftitemid): array {
        global $CFG, $DB;

        if (!in_array($filearea, [self::AREA_PROGRAMME, self::AREA_COURSEUNIT], true)) {
            throw new \invalid_parameter_exception('Invalid catalogue image file area');
        }
        if ($draftitemid <= 0 || $itemid <= 0) {
            throw new \invalid_parameter_exception('Missing draft or item id');
        }

        if ($filearea === self::AREA_PROGRAMME) {
            if (!$DB->record_exists('local_btiportal_programme', ['id' => $itemid])) {
                throw new \invalid_parameter_exception('Programme not found');
            }
            $table = 'local_btiportal_programme';
        } else {
            if (!$DB->record_exists('local_btiportal_course_unit', ['id' => $itemid])) {
                throw new \invalid_parameter_exception('Course unit not found');
            }
            $table = 'local_btiportal_course_unit';
        }

        require_once($CFG->libdir . '/filelib.php');
        $context = \context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_btiportal', $filearea, $itemid);

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'local_btiportal',
            $filearea,
            $itemid,
            [
                'subdirs' => 0,
                'maxfiles' => 1,
                'accepted_types' => ['image'],
            ]
        );

        $files = $fs->get_area_files($context->id, 'local_btiportal', $filearea, $itemid, 'filename', false);
        $stored = reset($files);
        if (!$stored) {
            throw new \moodle_exception('nofile', 'error');
        }

        $url = \moodle_url::make_webservice_pluginfile_url(
            $context->id,
            'local_btiportal',
            $filearea,
            $itemid,
            '/',
            $stored->get_filename()
        );
        $fileurl = $url->out(false);

        $DB->set_field($table, 'imageurl', $fileurl, ['id' => $itemid]);
        $DB->set_field($table, 'timemodified', time(), ['id' => $itemid]);

        return [
            'fileurl' => $fileurl,
            'filename' => $stored->get_filename(),
            'itemid' => $itemid,
            'filearea' => $filearea,
        ];
    }
}
