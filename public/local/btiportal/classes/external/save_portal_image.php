<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\image_helper;

/**
 * Save a course or unit cover image from a Moodle draft area into permanent plugin storage.
 */
class save_portal_image extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'filearea' => new external_value(PARAM_ALPHA, 'courseimage or unitimage'),
            'itemid' => new external_value(PARAM_INT, 'courseid for courseimage, sectionid for unitimage'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item id from webservice/upload.php'),
            'courseid' => new external_value(PARAM_INT, 'Course id (required for auth checks)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $filearea, int $itemid, int $draftitemid, int $courseid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'filearea' => $filearea,
            'itemid' => $itemid,
            'draftitemid' => $draftitemid,
            'courseid' => $courseid,
        ]);

        $filearea = (string)$params['filearea'];
        $itemid = (int)$params['itemid'];
        $draftitemid = (int)$params['draftitemid'];
        $courseid = (int)$params['courseid'];

        if ($filearea === image_helper::AREA_COURSE) {
            $courseid = $itemid;
        }
        if ($courseid <= 0) {
            throw new \invalid_parameter_exception('courseid is required');
        }

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);

        return image_helper::save_from_draft($filearea, $itemid, $draftitemid, $courseid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'fileurl' => new external_value(PARAM_URL, 'Permanent Moodle webservice pluginfile URL'),
            'filename' => new external_value(PARAM_FILE, 'Stored filename'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }
}
