<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;
use local_btiportal\image_helper;

/**
 * Return portal course/unit cover image URLs stored by local_btiportal_save_portal_image.
 */
class get_portal_images extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Course ids',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(array $courseids = []): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), ['courseids' => $courseids]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        $allowed = [];
        foreach ($courseids as $courseid) {
            $courseid = (int)$courseid;
            if ($courseid > 0 && content_helper::user_can_view_course((int)$USER->id, $courseid)) {
                $allowed[] = $courseid;
            }
        }

        return image_helper::get_for_courses($allowed);
    }

    public static function execute_returns(): external_single_structure {
        $unit = new external_single_structure([
            'sectionid' => new external_value(PARAM_INT, 'course_sections.id'),
            'fileurl' => new external_value(PARAM_URL, 'Unit cover webservice pluginfile URL'),
        ]);

        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'courseimageurl' => new external_value(PARAM_RAW, 'Course cover URL (may be empty)'),
                    'units' => new external_multiple_structure($unit, 'Unit covers'),
                ]),
                'Portal images by course'
            ),
        ]);
    }
}
