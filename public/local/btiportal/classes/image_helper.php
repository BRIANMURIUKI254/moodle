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
