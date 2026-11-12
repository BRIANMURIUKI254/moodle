<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\assessment_access_helper;

class get_assessment_access extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        try {
            return assessment_access_helper::assert_can_access_assessment($userid, (int)$params['cmid'], false);
        } catch (\Throwable $exception) {
            assessment_access_helper::rethrow_typed($exception);
            throw $exception;
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'code' => new external_value(PARAM_ALPHANUMEXT, 'ok or a typed denial code'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'module' => new external_value(PARAM_PLUGIN, 'quiz or assign'),
            'instanceid' => new external_value(PARAM_INT, 'Activity instance id'),
            'selfhealed' => new external_value(PARAM_INT, '1 when enrolment was repaired during a write launch'),
            'pluginversion' => new external_value(PARAM_TEXT, 'local_btiportal version.php timestamp'),
            'pluginrelease' => new external_value(PARAM_TEXT, 'local_btiportal release string'),
            'gitsha' => new external_value(PARAM_TEXT, 'Short git SHA captured at upgrade'),
        ]);
    }
}
