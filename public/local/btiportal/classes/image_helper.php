<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Persist portal cover images (course / unit) into Moodle file storage.
 */
class image_helper {
    public const AREA_COURSE = 'courseimage';
    public const AREA_UNIT = 'unitimage';

    /**
     * @return array{fileurl:string,filename:string}
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

        // Replace any previous image for this item.
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
}
