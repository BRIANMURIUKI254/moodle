<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\image_helper;

/**
 * Save a programme or academic course-unit cover image (admin catalogue).
 */
class save_catalogue_image extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'filearea' => new external_value(PARAM_ALPHA, 'programmeimage or courseunitimage'),
            'itemid' => new external_value(PARAM_INT, 'Programme id or course unit id'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item id from webservice/upload.php'),
        ]);
    }

    public static function execute(string $filearea, int $itemid, int $draftitemid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'filearea' => $filearea,
            'itemid' => $itemid,
            'draftitemid' => $draftitemid,
        ]);

        academic_auth_helper::require_manage_academic();

        return image_helper::save_catalogue_image(
            (string)$params['filearea'],
            (int)$params['itemid'],
            (int)$params['draftitemid']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'fileurl' => new external_value(PARAM_URL, 'Permanent Moodle webservice pluginfile URL'),
            'filename' => new external_value(PARAM_FILE, 'Stored filename'),
            'itemid' => new external_value(PARAM_INT, 'Catalogue record id'),
            'filearea' => new external_value(PARAM_ALPHA, 'File area'),
        ]);
    }
}
