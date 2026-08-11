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
use local_btiportal\academic_calendar_helper;

class create_semester extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'code' => new external_value(PARAM_TEXT, 'Code'),
            'sequence' => new external_value(PARAM_INT, 'Sequence within year'),
            'startdate' => new external_value(PARAM_INT, 'Start unix'),
            'enddate' => new external_value(PARAM_INT, 'End unix'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'upcoming'),
            'iscurrent' => new external_value(PARAM_BOOL, 'Mark current in year', VALUE_DEFAULT, false),
        ]);
    }
    public static function execute(
        int $academicyearid, string $name, string $code, int $sequence,
        int $startdate, int $enddate, string $status = 'upcoming', bool $iscurrent = false
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'academicyearid', 'name', 'code', 'sequence', 'startdate', 'enddate', 'status', 'iscurrent'
        ));
        academic_auth_helper::require_manage_academic();
        $result = academic_calendar_helper::create_semester($params);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_calendar_helper::semester_structure_keys());
    }
}
