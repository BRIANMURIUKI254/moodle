<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assignment_meta_helper;

/**
 * Return portal assessment categories for assignments in the given courses.
 */
class get_assignment_meta extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Course ids to load assignment meta for',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(array $courseids = []): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseids' => $courseids,
        ]);
        $courseids = array_values(array_unique(array_map('intval', $params['courseids'])));

        $allowed = [];
        foreach ($courseids as $courseid) {
            if ($courseid <= 0) {
                continue;
            }
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            self::validate_context($context);
            if (is_enrolled($context, $USER, '', true)
                || is_siteadmin()
                || has_capability('moodle/course:view', $context)
                || has_capability('moodle/grade:viewall', $context)
            ) {
                $allowed[] = $courseid;
            }
        }

        return [
            'assignments' => assignment_meta_helper::get_by_courseids($allowed),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assignments' => new external_multiple_structure(
                new external_single_structure([
                    'assignmentid' => new external_value(PARAM_INT, 'assign.id'),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'unitid' => new external_value(PARAM_INT, 'Unit section id'),
                    'category' => new external_value(PARAM_ALPHA, 'CAT, EXAM, or ASSIGNMENT'),
                ])
            ),
        ]);
    }
}
