<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_academic_progression_helper;

class preview_student_promotion extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'curriculumyearid' => new external_value(PARAM_INT, 'Optional explicit target year', VALUE_DEFAULT, 0),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Optional target semester', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'Target academic year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Target institutional semester', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $userid,
        int $curriculumyearid = 0,
        int $curriculumsemesterid = 0,
        int $academicyearid = 0,
        int $semesterid = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid'
        ));
        academic_auth_helper::require_view_academic();
        $target = [];
        foreach (['curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid'] as $f) {
            if ($params[$f] > 0) {
                $target[$f] = $params[$f];
            }
        }
        $result = student_academic_progression_helper::preview_promotion((int)$params['userid'], $target);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['preview']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_academic_progression_helper::preview_structure_keys());
    }
}
