<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\fees_messaging_helper;

class send_fees_message extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'touserid' => new external_value(PARAM_INT, 'Recipient Moodle user id'),
            'text' => new external_value(PARAM_TEXT, 'Message body'),
        ]);
    }

    public static function execute(int $touserid, string $text): array {
        global $USER;
        academic_auth_helper::require_student_self_read();
        $params = self::validate_parameters(self::execute_parameters(), [
            'touserid' => $touserid,
            'text' => $text,
        ]);
        fees_messaging_helper::send((int)$USER->id, (int)$params['touserid'], $params['text']);
        return ['sent' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sent' => new external_value(PARAM_BOOL, 'Message queued'),
        ]);
    }
}