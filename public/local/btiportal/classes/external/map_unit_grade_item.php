<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;
use local_btiportal\gradebook_helper;

/**
 * Map a Moodle grade item to a portal unit (stamps itemmodule/itemtype from mdl_grade_items).
 */
class map_unit_grade_item extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id (course_sections.id)'),
            'gradeitemid' => new external_value(PARAM_INT, 'Moodle grade_items.id'),
        ]);
    }

    public static function execute(int $courseid, int $unitid, int $gradeitemid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'gradeitemid' => $gradeitemid,
        ]);

        $courseid = (int)$params['courseid'];
        $unitid = (int)$params['unitid'];
        $gradeitemid = (int)$params['gradeitemid'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);

        if (!is_siteadmin()
            && !content_helper::user_can_manage_course((int)$USER->id, $courseid)
            && !gradebook_helper::user_is_unit_teacher((int)$USER->id, $courseid, $unitid)
        ) {
            throw new \moodle_exception('nopermissions', 'error', '', 'map grade items');
        }

        if (!has_capability('moodle/grade:edit', $context)
            && !has_capability('moodle/course:manageactivities', $context)
            && !is_siteadmin()
        ) {
            throw new \moodle_exception('nopermissions', 'error', '', 'map grade items');
        }

        return gradebook_helper::map_grade_item($courseid, $unitid, $gradeitemid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Mapping row id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id'),
            'gradeitemid' => new external_value(PARAM_INT, 'Grade item id'),
            'itemmodule' => new external_value(PARAM_TEXT, 'itemmodule'),
            'itemtype' => new external_value(PARAM_TEXT, 'itemtype'),
        ]);
    }
}
