<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\assessment_helper;

class duplicate_assessment extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id to duplicate'),
        ]);
    }

    public static function execute(int $cmid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cm = get_coursemodule_from_id(null, (int)$params['cmid'], 0, false, MUST_EXIST);
        self::validate_context(\context_course::instance((int)$cm->course));
        return assessment_helper::duplicate_assessment((int)$params['cmid'], (int)$USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return get_assessments::assessment_structure();
    }
}
