<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_academic_history_helper;

/** Self-scoped academic history for the signed-in student. */
class get_my_student_academic_history extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'actiontype' => new external_value(PARAM_ALPHANUMEXT, 'Optional action filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $actiontype = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), ['actiontype' => $actiontype]);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $action = $params['actiontype'] !== '' ? $params['actiontype'] : null;
        $items = student_academic_history_helper::list_for_user($userid, $action);
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(student_academic_history_helper::structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
