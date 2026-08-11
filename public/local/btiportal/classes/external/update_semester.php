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

class update_semester extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Id'),
            'academicyearid' => new external_value(PARAM_INT, 'Year id', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'Name', VALUE_DEFAULT, ''),
            'code' => new external_value(PARAM_TEXT, 'Code', VALUE_DEFAULT, ''),
            'sequence' => new external_value(PARAM_INT, 'Sequence', VALUE_DEFAULT, 0),
            'startdate' => new external_value(PARAM_INT, 'Start', VALUE_DEFAULT, 0),
            'enddate' => new external_value(PARAM_INT, 'End', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
            'iscurrent' => new external_value(PARAM_INT, '0/1/-1', VALUE_DEFAULT, -1),
        ]);
    }
    public static function execute(
        int $id, int $academicyearid = 0, string $name = '', string $code = '', int $sequence = 0,
        int $startdate = 0, int $enddate = 0, string $status = '', int $iscurrent = -1
    ): array {
        self::validate_parameters(self::execute_parameters(), compact(
            'id', 'academicyearid', 'name', 'code', 'sequence', 'startdate', 'enddate', 'status', 'iscurrent'
        ));
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($academicyearid > 0) { $input['academicyearid'] = $academicyearid; }
        if ($name !== '') { $input['name'] = $name; }
        if ($code !== '') { $input['code'] = $code; }
        if ($sequence > 0) { $input['sequence'] = $sequence; }
        if ($startdate > 0) { $input['startdate'] = $startdate; }
        if ($enddate > 0) { $input['enddate'] = $enddate; }
        if ($status !== '') { $input['status'] = $status; }
        if ($iscurrent === 0 || $iscurrent === 1) { $input['iscurrent'] = $iscurrent; }
        $result = academic_calendar_helper::update_semester($id, $input);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_calendar_helper::semester_structure_keys());
    }
}
