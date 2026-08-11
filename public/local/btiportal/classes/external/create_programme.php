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
use local_btiportal\programme_helper;

class create_programme extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_TEXT, 'Programme code'),
            'name' => new external_value(PARAM_TEXT, 'Programme name'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'departmentid' => new external_value(PARAM_INT, 'Owning department id', VALUE_DEFAULT, 0),
            'moodlecategoryid' => new external_value(PARAM_INT, 'Optional Moodle category', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
            'awardtype' => new external_value(PARAM_ALPHANUMEXT, 'Award type', VALUE_DEFAULT, 'bachelor'),
            'duration' => new external_value(PARAM_FLOAT, 'Nominal duration', VALUE_DEFAULT, 0),
            'durationunit' => new external_value(PARAM_ALPHANUMEXT, 'Duration unit', VALUE_DEFAULT, 'years'),
        ]);
    }
    public static function execute(
        string $code, string $name, string $description = '', int $departmentid = 0,
        int $moodlecategoryid = 0, string $status = 'active', string $awardtype = 'bachelor',
        float $duration = 0, string $durationunit = 'years'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'code' => $code, 'name' => $name, 'description' => $description,
            'departmentid' => $departmentid, 'moodlecategoryid' => $moodlecategoryid, 'status' => $status,
            'awardtype' => $awardtype, 'duration' => $duration, 'durationunit' => $durationunit,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = programme_helper::create($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(programme_helper::programme_structure_keys());
    }
}
