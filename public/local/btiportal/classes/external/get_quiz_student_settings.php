<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\quiz_reopen_helper;

class get_quiz_student_settings extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'Student user id'),
        ]);
    }

    public static function execute(int $quizid, int $courseid, int $userid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid,
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        $cm = get_coursemodule_from_instance(
            'quiz',
            (int)$params['quizid'],
            (int)$params['courseid'],
            false,
            MUST_EXIST
        );
        self::validate_context(\context_module::instance((int)$cm->id));
        return quiz_reopen_helper::get_student_settings(
            (int)$params['quizid'],
            (int)$params['courseid'],
            (int)$params['userid'],
            (int)$USER->id
        );
    }

    public static function execute_returns(): external_single_structure {
        return self::settings_structure();
    }

    public static function settings_structure(): external_single_structure {
        return new external_single_structure([
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student user id'),
            'latestattemptnumber' => new external_value(PARAM_INT, 'Student latest attempt number'),
            'timeopen' => new external_value(PARAM_INT, 'Effective student opening time'),
            'timeclose' => new external_value(PARAM_INT, 'Effective student deadline'),
            'timelimit' => new external_value(PARAM_INT, 'Effective time limit in seconds'),
            'attempts' => new external_value(PARAM_INT, 'Effective maximum attempts; zero is unlimited'),
            'requirefullscreen' => new external_value(PARAM_INT, 'Fullscreen requirement'),
            'maxwarnings' => new external_value(PARAM_INT, 'Maximum exam warnings'),
            'warningaction' => new external_value(PARAM_ALPHANUMEXT, 'Action at warning limit'),
            'flagged' => new external_value(PARAM_INT, 'Whether the latest attempt is flagged'),
            'warningcount' => new external_value(PARAM_INT, 'Warnings on the latest attempt'),
            'latestgrade' => new external_value(PARAM_INT, 'Whether the latest attempt grade is used'),
        ]);
    }
}
