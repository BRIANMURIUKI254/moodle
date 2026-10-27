<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\gradebook_helper;
use local_btiportal\lecturer_teaching_helper;

/**
 * Return the unit-scoped gradebook (roster + mapped grade items + current grades).
 */
class get_unit_gradebook extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id (Moodle course_sections.id)'),
        ]);
    }

    public static function execute(int $courseid, int $unitid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unitid' => $unitid,
        ]);
        $courseid = (int)$params['courseid'];
        $unitid = (int)$params['unitid'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);

        // Final Exams are course-level (unitid = 0); ordinary gradebooks
        // retain the existing unit-teacher authorization path.
        if ($unitid === 0) {
            lecturer_teaching_helper::require_moodle_course_teacher(
                (int)$USER->id,
                $courseid
            );
        } else {
            gradebook_helper::require_unit_teacher(
                (int)$USER->id,
                $courseid,
                $unitid
            );
        }

        // Layer 2: Moodle grading capability.
        if (!has_capability('moodle/grade:viewall', $context)
            && !has_capability('moodle/grade:edit', $context)
            && !has_capability('mod/assign:grade', $context)
        ) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view grades');
        }

        if ($unitid === 0) {
            return gradebook_helper::get_final_exam_gradebook($courseid);
        }

        return gradebook_helper::get_unit_gradebook($courseid, $unitid);
    }

    public static function execute_returns(): external_single_structure {
        $gradeitemstructure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Grade item id'),
            'name' => new external_value(PARAM_TEXT, 'Grade item name'),
            'itemmodule' => new external_value(PARAM_TEXT, 'itemmodule from mdl_grade_items'),
            'itemtype' => new external_value(PARAM_TEXT, 'itemtype from mdl_grade_items'),
            'iteminstance' => new external_value(PARAM_INT, 'iteminstance'),
            'grademax' => new external_value(PARAM_FLOAT, 'Max grade'),
            'readonly' => new external_value(PARAM_BOOL, 'True when not editable in portal'),
            'readonlyreason' => new external_value(PARAM_TEXT, 'Why read-only', VALUE_DEFAULT, ''),
            'category' => new external_value(
                PARAM_ALPHANUMEXT,
                'CAT, EXAM, ASSIGNMENT, or empty when uncategorized / quiz',
                VALUE_DEFAULT,
                ''
            ),
        ]);

        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Student user id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                ])
            ),
            'gradeitems' => new external_multiple_structure(
                $gradeitemstructure,
                'Flat list (same items as inside groups) for compatibility'
            ),
            'grades' => new external_multiple_structure(
                new external_single_structure([
                    'studentid' => new external_value(PARAM_INT, 'Student id'),
                    'gradeitemid' => new external_value(PARAM_INT, 'Grade item id'),
                    'grade' => new external_value(PARAM_FLOAT, 'Current grade'),
                ])
            ),
            'groups' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'CAT, ASSIGNMENT, EXAM, QUIZ, or UNCATEGORIZED'),
                    'label' => new external_value(PARAM_TEXT, 'Display label'),
                    'type' => new external_value(PARAM_ALPHA, 'assessment, quiz, or uncategorized'),
                    'configured' => new external_value(PARAM_BOOL, 'True when this unit has ≥1 item in the group'),
                    'itemcount' => new external_value(PARAM_INT, 'Number of items in the group'),
                    'items' => new external_multiple_structure($gradeitemstructure, 'Items in this group'),
                ]),
                'Always includes CAT, ASSIGNMENT, EXAM, QUIZ, UNCATEGORIZED (empty groups still listed)'
            ),
            'categoryaverages' => new external_multiple_structure(
                new external_single_structure([
                    'studentid' => new external_value(PARAM_INT, 'Student id'),
                    'category' => new external_value(PARAM_ALPHA, 'CAT, EXAM, or ASSIGNMENT'),
                    'average' => new external_value(PARAM_FLOAT, 'Simple average percent within category'),
                    'count' => new external_value(PARAM_INT, 'Graded items counted in average'),
                    'pointsearned' => new external_value(PARAM_FLOAT, 'Sum of points earned in category'),
                    'pointspossible' => new external_value(PARAM_FLOAT, 'Sum of max points for graded items'),
                ]),
                'Per-student per-category averages (Uncategorized/Quiz excluded)',
                VALUE_DEFAULT,
                []
            ),
            'combinedaverages' => new external_multiple_structure(
                new external_single_structure([
                    'studentid' => new external_value(PARAM_INT, 'Student id'),
                    'average' => new external_value(PARAM_FLOAT, 'Mean of category averages with ≥1 graded item'),
                    'categoriescounted' => new external_value(PARAM_INT, 'How many category averages were averaged'),
                ]),
                'Combined total per student (mean of CAT/ASSIGNMENT/EXAM averages only)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
