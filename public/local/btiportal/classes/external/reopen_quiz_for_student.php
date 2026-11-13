<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use local_btiportal\quiz_reopen_helper;

class reopen_quiz_for_student extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new \core_external\external_value(PARAM_INT, 'Quiz id'),
            'courseid' => new \core_external\external_value(PARAM_INT, 'Course id'),
            'userid' => new \core_external\external_value(PARAM_INT, 'Student user id'),
            'timeopen' => new \core_external\external_value(PARAM_INT, 'Student opening time'),
            'timeclose' => new \core_external\external_value(PARAM_INT, 'Student submission deadline'),
            'timelimit' => new \core_external\external_value(PARAM_INT, 'Time limit in seconds'),
            'attempts' => new \core_external\external_value(PARAM_INT, 'Maximum attempts; zero is unlimited'),
            'requirefullscreen' => new \core_external\external_value(PARAM_INT, 'Require fullscreen'),
            'maxwarnings' => new \core_external\external_value(PARAM_INT, 'Maximum exam warnings'),
            'warningaction' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'Action at warning limit'),
            'flagged' => new \core_external\external_value(PARAM_INT, 'Flag latest attempt'),
            'warningcount' => new \core_external\external_value(PARAM_INT, 'Latest attempt warning count'),
        ]);
    }

    public static function execute(
        int $quizid,
        int $courseid,
        int $userid,
        int $timeopen,
        int $timeclose,
        int $timelimit,
        int $attempts,
        int $requirefullscreen,
        int $maxwarnings,
        string $warningaction,
        int $flagged,
        int $warningcount
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid,
            'courseid' => $courseid,
            'userid' => $userid,
            'timeopen' => $timeopen,
            'timeclose' => $timeclose,
            'timelimit' => $timelimit,
            'attempts' => $attempts,
            'requirefullscreen' => $requirefullscreen,
            'maxwarnings' => $maxwarnings,
            'warningaction' => $warningaction,
            'flagged' => $flagged,
            'warningcount' => $warningcount,
        ]);
        $cm = get_coursemodule_from_instance(
            'quiz',
            (int)$params['quizid'],
            (int)$params['courseid'],
            false,
            MUST_EXIST
        );
        self::validate_context(\context_module::instance((int)$cm->id));
        return quiz_reopen_helper::reopen_for_student($params, (int)$USER->id);
    }

    public static function execute_returns(): \core_external\external_single_structure {
        return get_quiz_student_settings::settings_structure();
    }
}
