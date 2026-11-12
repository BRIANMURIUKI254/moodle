<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assignment_grading_helper;

class release_assignment_grades extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'Assignment instance id'),
        ]);
    }

    public static function execute(int $assignmentid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
        ]);
        $cm = get_coursemodule_from_instance('assign', (int)$params['assignmentid'], 0, false, MUST_EXIST);
        self::validate_context(\context_module::instance((int)$cm->id));
        return assignment_grading_helper::release_grades((int)$params['assignmentid'], (int)$USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True when release was attempted'),
            'released' => new external_value(PARAM_INT, 'Grades moved to released'),
            'message' => new external_value(PARAM_TEXT, 'Result'),
        ]);
    }
}
