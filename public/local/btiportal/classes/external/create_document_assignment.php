<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assignment_helper;
use local_btiportal\gradebook_helper;

/**
 * Create a Moodle assignment (file submission) in a unit and map its grade item.
 */
class create_document_assignment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id (course_sections.id)'),
            'name' => new external_value(PARAM_TEXT, 'Assignment title'),
            'intro' => new external_value(PARAM_RAW, 'Brief / instructions (intro HTML/text)', VALUE_DEFAULT, ''),
            'grademax' => new external_value(PARAM_FLOAT, 'Maximum grade', VALUE_DEFAULT, 100),
            'introattachments' => new external_value(
                PARAM_INT,
                'Optional draft area item id for assign introattachments (brief file)',
                VALUE_DEFAULT,
                0
            ),
            'category' => new external_value(
                PARAM_ALPHA,
                'Assessment category: CAT, EXAM, or ASSIGNMENT',
                VALUE_REQUIRED
            ),
        ]);
    }

    public static function execute(
        int $courseid,
        int $unitid,
        string $name,
        string $intro = '',
        float $grademax = 100,
        int $introattachments = 0,
        string $category = ''
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'name' => $name,
            'intro' => $intro,
            'grademax' => $grademax,
            'introattachments' => $introattachments,
            'category' => $category,
        ]);

        $courseid = (int)$params['courseid'];
        $unitid = (int)$params['unitid'];
        $name = (string)$params['name'];
        $intro = (string)$params['intro'];
        $grademax = (float)$params['grademax'];
        $introattachments = (int)$params['introattachments'];
        $category = (string)$params['category'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);

        gradebook_helper::require_unit_teacher((int)$USER->id, $courseid, $unitid);

        if (!has_capability('moodle/course:manageactivities', $context)
            && !has_capability('mod/assign:addinstance', $context)
            && !is_siteadmin()
        ) {
            throw new \moodle_exception('nopermissions', 'error', '', 'create assignment');
        }

        return assignment_helper::create_document_assignment(
            $courseid,
            $unitid,
            $name,
            $intro,
            $grademax,
            $introattachments,
            $category
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id'),
            'assignmentid' => new external_value(PARAM_INT, 'assign.id'),
            'cmid' => new external_value(PARAM_INT, 'course_modules.id'),
            'name' => new external_value(PARAM_TEXT, 'Assignment name'),
            'intro' => new external_value(PARAM_RAW, 'Intro / brief'),
            'gradeitemid' => new external_value(PARAM_INT, 'Mapped grade item id'),
            'grademax' => new external_value(PARAM_FLOAT, 'Max grade'),
            'mapped' => new external_value(PARAM_BOOL, 'True when grade item mapped to unit'),
            'category' => new external_value(PARAM_ALPHA, 'CAT, EXAM, or ASSIGNMENT'),
        ]);
    }
}
