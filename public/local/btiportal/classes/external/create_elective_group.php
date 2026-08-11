<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\elective_helper;

class create_elective_group extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'yearofstudy' => new external_value(PARAM_INT, 'Year of study'),
            'semestersequence' => new external_value(PARAM_INT, 'Semester sequence'),
            'code' => new external_value(PARAM_TEXT, 'Elective group code'),
            'name' => new external_value(PARAM_TEXT, 'Elective group name'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'minselect' => new external_value(PARAM_INT, 'Minimum selections', VALUE_DEFAULT, 1),
            'maxselect' => new external_value(PARAM_INT, 'Maximum selections', VALUE_DEFAULT, 1),
            'mincredits' => new external_value(PARAM_FLOAT, 'Minimum credits', VALUE_DEFAULT, 0),
            'maxcredits' => new external_value(PARAM_FLOAT, 'Maximum credits', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
        ]);
    }

    public static function execute(
        int $curriculumid,
        int $yearofstudy,
        int $semestersequence,
        string $code,
        string $name,
        string $description = '',
        int $minselect = 1,
        int $maxselect = 1,
        float $mincredits = 0,
        float $maxcredits = 0,
        string $status = 'active'
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'curriculumid' => $curriculumid,
            'yearofstudy' => $yearofstudy,
            'semestersequence' => $semestersequence,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'minselect' => $minselect,
            'maxselect' => $maxselect,
            'mincredits' => $mincredits,
            'maxcredits' => $maxcredits,
            'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $result = elective_helper::create_group($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(elective_helper::group_structure_keys());
    }
}
