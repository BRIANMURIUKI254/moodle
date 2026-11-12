<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assignment_grading_helper;

class bulk_mark_assignment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'Assignment instance id'),
            'grade' => new external_value(PARAM_FLOAT, 'Mark to apply to every ungraded submission'),
            'confirm' => new external_value(PARAM_BOOL, 'Must be true. There is no silent bulk mark.'),
        ]);
    }

    public static function execute(int $assignmentid, float $grade, bool $confirm): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'grade' => $grade,
            'confirm' => $confirm,
        ]);
        $cm = get_coursemodule_from_instance('assign', (int)$params['assignmentid'], 0, false, MUST_EXIST);
        self::validate_context(\context_module::instance((int)$cm->id));
        return assignment_grading_helper::bulk_mark_ungraded(
            (int)$params['assignmentid'],
            (float)$params['grade'],
            !empty($params['confirm']),
            (int)$USER->id
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True when the request was accepted'),
            'updated' => new external_value(PARAM_INT, 'Submissions marked'),
            'message' => new external_value(PARAM_TEXT, 'Result'),
        ]);
    }
}
