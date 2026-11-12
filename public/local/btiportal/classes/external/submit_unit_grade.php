<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\gradebook_helper;

/**
 * Submit a single grade for a unit-mapped grade item.
 */
class submit_unit_grade extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'unitid' => new external_value(PARAM_INT, 'Unit id (Moodle course_sections.id)'),
            'studentid' => new external_value(PARAM_INT, 'Student user id'),
            'gradeitemid' => new external_value(PARAM_INT, 'Moodle grade item id'),
            'grade' => new external_value(PARAM_FLOAT, 'Grade value to write'),
            'feedback' => new external_value(PARAM_RAW, 'Assignment feedback comment', VALUE_DEFAULT, ''),
            'assignmentid' => new external_value(PARAM_INT, 'Assignment instance when the grade item is resolved from it', VALUE_DEFAULT, 0),
            'attemptnumber' => new external_value(PARAM_INT, 'Assignment attempt; -1 is the latest', VALUE_DEFAULT, -1),
            'workflowstate' => new external_value(PARAM_ALPHA, 'Moodle marking workflow state, or empty', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $courseid,
        int $unitid,
        int $studentid,
        int $gradeitemid,
        float $grade,
        string $feedback = '',
        int $assignmentid = 0,
        int $attemptnumber = -1,
        string $workflowstate = ''
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'studentid' => $studentid,
            'gradeitemid' => $gradeitemid,
            'grade' => $grade,
            'feedback' => $feedback,
            'assignmentid' => $assignmentid,
            'attemptnumber' => $attemptnumber,
            'workflowstate' => $workflowstate,
        ]);

        $courseid = (int)$params['courseid'];
        $unitid = (int)$params['unitid'];
        $studentid = (int)$params['studentid'];
        $gradeitemid = (int)$params['gradeitemid'];
        $grade = (float)$params['grade'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);

        // Layer 1: re-verify unit teacher assignment on every write.
        gradebook_helper::require_unit_teacher((int)$USER->id, $courseid, $unitid);

        // Layer 2: Moodle grading capability (course-level gate; item path re-checks specifics).
        if (!has_capability('moodle/grade:edit', $context)
            && !has_capability('mod/assign:grade', $context)
        ) {
            throw new \moodle_exception('nopermissions', 'error', '', 'edit grades');
        }

        return gradebook_helper::submit_unit_grade(
            $courseid,
            $unitid,
            $studentid,
            $gradeitemid,
            $grade,
            (string)$params['feedback'],
            (int)$params['assignmentid'],
            (int)$params['attemptnumber'],
            (string)$params['workflowstate']
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True when a write succeeded'),
            'editable' => new external_value(PARAM_BOOL, 'False when item is read-only here'),
            'writesource' => new external_value(PARAM_TEXT, 'Write path used or readonly_quiz'),
            'grade' => new external_value(PARAM_FLOAT, 'Confirmed grade re-read from Moodle (-1 if none)', VALUE_DEFAULT, -1),
            'message' => new external_value(PARAM_TEXT, 'Human-readable status'),
        ]);
    }
}
