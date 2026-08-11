<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_delivery_plan_helper;

class preview_delivery_for_placement extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'curriculumyearid' => new external_value(PARAM_INT, 'Curriculum year id'),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Curriculum semester id'),
            'programmeid' => new external_value(PARAM_INT, 'Programme id', VALUE_DEFAULT, 0),
            'schoolid' => new external_value(PARAM_INT, 'School id', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Department id', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'Optional student user id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $curriculumid,
        int $curriculumyearid,
        int $curriculumsemesterid,
        int $programmeid = 0,
        int $schoolid = 0,
        int $departmentid = 0,
        int $academicyearid = 0,
        int $semesterid = 0,
        int $userid = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'curriculumid', 'curriculumyearid', 'curriculumsemesterid', 'programmeid',
            'schoolid', 'departmentid', 'academicyearid', 'semesterid', 'userid'
        ));
        academic_auth_helper::require_view_academic();
        $result = student_delivery_plan_helper::preview_for_placement([
            'curriculumid' => (int)$params['curriculumid'],
            'curriculumyearid' => (int)$params['curriculumyearid'],
            'curriculumsemesterid' => (int)$params['curriculumsemesterid'],
            'programmeid' => (int)$params['programmeid'],
            'schoolid' => (int)$params['schoolid'],
            'departmentid' => (int)$params['departmentid'],
            'academicyearid' => (int)$params['academicyearid'],
            'semesterid' => (int)$params['semesterid'],
        ], (int)$params['userid']);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['plan']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(student_delivery_plan_helper::plan_structure_keys());
    }
}
