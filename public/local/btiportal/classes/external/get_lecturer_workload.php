<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\lecturer_teaching_helper;

class get_lecturer_workload extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Lecturer Moodle user id'),
            'academicyearid' => new external_value(PARAM_INT, 'Filter by academic year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Filter by institutional semester', VALUE_DEFAULT, 0),
            'includelegacy' => new external_value(PARAM_BOOL, 'Include unperioded legacy assignments', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        int $userid,
        int $academicyearid = 0,
        int $semesterid = 0,
        bool $includelegacy = false
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'includelegacy' => $includelegacy,
        ]);
        academic_auth_helper::require_view_lecturer_record((int)$params['userid']);
        $year = $params['academicyearid'] > 0 ? (int)$params['academicyearid'] : null;
        $sem = $params['semesterid'] > 0 ? (int)$params['semesterid'] : null;
        $data = lecturer_teaching_helper::get_workload(
            (int)$params['userid'],
            $year,
            $sem,
            (bool)$params['includelegacy']
        );
        return academic_api_response::for_external(academic_api_response::ok($data));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(lecturer_teaching_helper::workload_structure_keys());
    }
}
