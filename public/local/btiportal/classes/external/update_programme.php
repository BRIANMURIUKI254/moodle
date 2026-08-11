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

class update_programme extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Programme id'),
            'code' => new external_value(PARAM_TEXT, 'Programme code', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_TEXT, 'Programme name', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'departmentid' => new external_value(PARAM_INT, 'Owning department id', VALUE_DEFAULT, -1),
            'moodlecategoryid' => new external_value(PARAM_INT, 'Moodle category', VALUE_DEFAULT, -1),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
            'updatedescription' => new external_value(PARAM_BOOL, 'Apply description field', VALUE_DEFAULT, false),
            'awardtype' => new external_value(PARAM_ALPHANUMEXT, 'Award type', VALUE_DEFAULT, ''),
            'duration' => new external_value(PARAM_FLOAT, 'Nominal duration', VALUE_DEFAULT, -1),
            'durationunit' => new external_value(PARAM_ALPHANUMEXT, 'Duration unit', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(
        int $id, string $code = '', string $name = '', string $description = '',
        int $departmentid = -1, int $moodlecategoryid = -1, string $status = '',
        bool $updatedescription = false, string $awardtype = '', float $duration = -1,
        string $durationunit = ''
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'code' => $code, 'name' => $name, 'description' => $description,
            'departmentid' => $departmentid, 'moodlecategoryid' => $moodlecategoryid,
            'status' => $status, 'updatedescription' => $updatedescription,
            'awardtype' => $awardtype, 'duration' => $duration, 'durationunit' => $durationunit,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($code !== '') { $input['code'] = $code; }
        if ($name !== '') { $input['name'] = $name; }
        if ($updatedescription) { $input['description'] = $description; }
        if ($departmentid >= 0) { $input['departmentid'] = $departmentid; }
        if ($moodlecategoryid >= 0) { $input['moodlecategoryid'] = $moodlecategoryid; }
        if ($status !== '') { $input['status'] = $status; }
        if ($awardtype !== '') { $input['awardtype'] = $awardtype; }
        if ($duration >= 0) { $input['duration'] = $duration; }
        if ($durationunit !== '') { $input['durationunit'] = $durationunit; }
        $result = programme_helper::update($id, $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(programme_helper::programme_structure_keys());
    }
}
