<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\academic_delivery_compare_helper;

class compare_academic_delivery extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'schoolid' => new external_value(PARAM_INT, 'Target school id'),
            'departmentid' => new external_value(PARAM_INT, 'Target department id'),
            'programmeid' => new external_value(PARAM_INT, 'Target programme id'),
            'curriculumid' => new external_value(PARAM_INT, 'Target curriculum id'),
            'curriculumyearid' => new external_value(PARAM_INT, 'Target curriculum year id'),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Target curriculum semester id'),
            'academicyearid' => new external_value(PARAM_INT, 'Target academic year id', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Target institutional semester id', VALUE_DEFAULT, 0),
            'policy' => new external_value(
                PARAM_ALPHANUMEXT,
                'Delivery transition policy',
                VALUE_DEFAULT,
                'preserve_and_enrol_new'
            ),
        ]);
    }

    public static function execute(
        int $userid,
        int $schoolid,
        int $departmentid,
        int $programmeid,
        int $curriculumid,
        int $curriculumyearid,
        int $curriculumsemesterid,
        int $academicyearid = 0,
        int $semesterid = 0,
        string $policy = 'preserve_and_enrol_new'
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'schoolid', 'departmentid', 'programmeid', 'curriculumid',
            'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid', 'policy'
        ));
        academic_auth_helper::require_view_academic();
        $result = academic_delivery_compare_helper::compare(
            (int)$params['userid'],
            [
                'schoolid' => (int)$params['schoolid'],
                'departmentid' => (int)$params['departmentid'],
                'programmeid' => (int)$params['programmeid'],
                'curriculumid' => (int)$params['curriculumid'],
                'curriculumyearid' => (int)$params['curriculumyearid'],
                'curriculumsemesterid' => (int)$params['curriculumsemesterid'],
                'academicyearid' => (int)$params['academicyearid'],
                'semesterid' => (int)$params['semesterid'],
            ],
            $params['policy']
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['comparison']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(
            academic_delivery_compare_helper::comparison_structure_keys()
        );
    }
}
