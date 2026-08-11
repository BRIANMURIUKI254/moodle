<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\course_unit_teacher_helper;
use local_btiportal\lecturer_teaching_helper;

/**
 * Assign a lecturer to a course unit (wraps course_unit_teacher_helper).
 */
class assign_lecturer_course_unit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Lecturer Moodle user id'),
            'courseunitid' => new external_value(PARAM_INT, 'Course unit id'),
            'teachingrole' => new external_value(PARAM_ALPHANUMEXT, 'Teaching role', VALUE_DEFAULT, 'co_lecturer'),
            'isprimary' => new external_value(PARAM_BOOL, 'Primary lecturer', VALUE_DEFAULT, false),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'active'),
        ]);
    }

    public static function execute(
        int $userid,
        int $courseunitid,
        string $teachingrole = 'co_lecturer',
        bool $isprimary = false,
        string $status = 'active'
    ): array {
        global $USER, $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseunitid' => $courseunitid,
            'teachingrole' => $teachingrole,
            'isprimary' => $isprimary,
            'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        if (!$DB->record_exists('local_btiportal_course_unit', ['id' => (int)$params['courseunitid']])) {
            return academic_api_response::for_external(
                academic_api_response::fail_field('courseunitid', 'Course unit not found')
            );
        }
        $result = course_unit_teacher_helper::assign([
            'userid' => (int)$params['userid'],
            'courseunitid' => (int)$params['courseunitid'],
            'teachingrole' => $params['teachingrole'],
            'isprimary' => $params['isprimary'],
            'status' => $params['status'],
        ], (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        $items = lecturer_teaching_helper::list_course_units((int)$params['userid'], 'all');
        foreach ($items as $item) {
            if ((int)$item['id'] === (int)$result['record']['id']) {
                return academic_api_response::for_external(academic_api_response::ok($item));
            }
        }
        return academic_api_response::for_external(academic_api_response::ok(array_merge(
            $result['record'],
            [
                'unitcode' => '',
                'unitname' => '',
                'programmeid' => 0,
                'departmentid' => 0,
                'curriculumid' => 0,
                'yearofstudy' => 0,
                'semestersequence' => 0,
                'moodlecourseid' => 0,
                'unitstatus' => '',
            ]
        )));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(
            lecturer_teaching_helper::course_unit_assignment_structure_keys()
        );
    }
}
