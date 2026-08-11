<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\elective_helper;

class update_elective_group extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Elective group id'),
            'code' => new external_value(PARAM_TEXT, 'Code', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_TEXT, 'Name', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'yearofstudy' => new external_value(PARAM_INT, 'Year of study', VALUE_DEFAULT, 0),
            'semestersequence' => new external_value(PARAM_INT, 'Semester sequence', VALUE_DEFAULT, 0),
            'minselect' => new external_value(PARAM_INT, 'Minimum selections', VALUE_DEFAULT, -1),
            'maxselect' => new external_value(PARAM_INT, 'Maximum selections', VALUE_DEFAULT, -1),
            'mincredits' => new external_value(PARAM_FLOAT, 'Minimum credits', VALUE_DEFAULT, -1),
            'maxcredits' => new external_value(PARAM_FLOAT, 'Maximum credits', VALUE_DEFAULT, -1),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
            'updatedescription' => new external_value(PARAM_BOOL, 'Apply description field', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        int $id,
        string $code = '',
        string $name = '',
        string $description = '',
        int $yearofstudy = 0,
        int $semestersequence = 0,
        int $minselect = -1,
        int $maxselect = -1,
        float $mincredits = -1,
        float $maxcredits = -1,
        string $status = '',
        bool $updatedescription = false
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'yearofstudy' => $yearofstudy,
            'semestersequence' => $semestersequence,
            'minselect' => $minselect,
            'maxselect' => $maxselect,
            'mincredits' => $mincredits,
            'maxcredits' => $maxcredits,
            'status' => $status,
            'updatedescription' => $updatedescription,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($params['code'] !== '') {
            $input['code'] = $params['code'];
        }
        if ($params['name'] !== '') {
            $input['name'] = $params['name'];
        }
        if ($params['updatedescription']) {
            $input['description'] = $params['description'];
        }
        if ((int)$params['yearofstudy'] > 0) {
            $input['yearofstudy'] = (int)$params['yearofstudy'];
        }
        if ((int)$params['semestersequence'] > 0) {
            $input['semestersequence'] = (int)$params['semestersequence'];
        }
        if ((int)$params['minselect'] >= 0) {
            $input['minselect'] = (int)$params['minselect'];
        }
        if ((int)$params['maxselect'] >= 0) {
            $input['maxselect'] = (int)$params['maxselect'];
        }
        if ((float)$params['mincredits'] >= 0) {
            $input['mincredits'] = (float)$params['mincredits'];
        }
        if ((float)$params['maxcredits'] >= 0) {
            $input['maxcredits'] = (float)$params['maxcredits'];
        }
        if ($params['status'] !== '') {
            $input['status'] = $params['status'];
        }
        $result = elective_helper::update_group((int)$params['id'], $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(elective_helper::group_structure_keys());
    }
}
