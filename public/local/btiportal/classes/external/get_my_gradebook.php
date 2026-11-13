<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_gradebook_helper;

/**
 * Return the signed-in student's visible Moodle grades.
 */
class get_my_gradebook extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), []);
        $userid = academic_auth_helper::resolve_student_userid_for_read((int)$USER->id);

        return ['grades' => student_gradebook_helper::get_gradebook($userid)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'grades' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
                'coursename' => new external_value(PARAM_TEXT, 'Moodle course name'),
                'unitid' => new external_value(PARAM_INT, 'BTI course section id; zero for a course-level Final Exam'),
                'unitname' => new external_value(PARAM_TEXT, 'Course unit name; empty for a Final Exam'),
                'assessmentid' => new external_value(PARAM_INT, 'Moodle activity instance id'),
                'assessmentname' => new external_value(PARAM_TEXT, 'Assessment name'),
                'assessmenttype' => new external_value(PARAM_ALPHANUMEXT, 'BTI assessment type'),
                'assessmentdate' => new external_value(PARAM_INT, 'Assessment due or close date', VALUE_OPTIONAL),
                'gradedate' => new external_value(PARAM_INT, 'Date the grade was submitted or graded', VALUE_OPTIONAL),
                'score' => new external_value(PARAM_FLOAT, 'Visible grade', VALUE_OPTIONAL),
                'maximum' => new external_value(PARAM_FLOAT, 'Maximum grade when the range is valid', VALUE_OPTIONAL),
                'percentage' => new external_value(PARAM_FLOAT, 'Normalized grade percentage', VALUE_OPTIONAL),
                'status' => new external_value(PARAM_ALPHA, 'graded, pending, or provisional while essays still need marking'),
                'submitted' => new external_value(PARAM_BOOL, 'Whether the student submitted this assessment'),
                'isfinalexam' => new external_value(PARAM_BOOL, 'Whether this is a course-level Final Exam'),
                'feedback' => new external_value(PARAM_RAW, 'Visible Moodle grade feedback', VALUE_OPTIONAL),
                'feedbackformat' => new external_value(PARAM_INT, 'Moodle feedback format', VALUE_OPTIONAL),
            ])),
        ]);
    }
}
