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
use local_btiportal\academic_course_unit_helper;

class update_academic_course_unit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Course unit id'),
            'code' => new external_value(PARAM_TEXT, 'Code', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_TEXT, 'Name', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'programmeid' => new external_value(PARAM_INT, 'Programme', VALUE_DEFAULT, 0),
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum', VALUE_DEFAULT, 0),
            'yearofstudy' => new external_value(PARAM_INT, 'Year', VALUE_DEFAULT, 0),
            'semestersequence' => new external_value(PARAM_INT, 'Semester sequence', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Moodle course', VALUE_DEFAULT, 0),
            'sectionid' => new external_value(PARAM_INT, 'Moodle section', VALUE_DEFAULT, 0),
            'credithours' => new external_value(PARAM_FLOAT, 'Credits', VALUE_DEFAULT, 0),
            'unittype' => new external_value(PARAM_ALPHANUMEXT, 'Type', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
            'isrequired' => new external_value(PARAM_INT, '0/1/-1', VALUE_DEFAULT, -1),
            'updatedescription' => new external_value(PARAM_BOOL, 'Apply description', VALUE_DEFAULT, false),
        ]);
    }
    public static function execute(
        int $id, string $code = '', string $name = '', string $description = '',
        int $programmeid = 0, int $curriculumid = 0, int $yearofstudy = 0, int $semestersequence = 0,
        int $courseid = 0, int $sectionid = 0, float $credithours = 0, string $unittype = '',
        string $status = '', int $isrequired = -1, bool $updatedescription = false
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact(
            'id', 'code', 'name', 'description', 'programmeid', 'curriculumid', 'yearofstudy',
            'semestersequence', 'courseid', 'sectionid', 'credithours', 'unittype', 'status',
            'isrequired', 'updatedescription'
        ));
        $existing = academic_course_unit_helper::get($id);
        if (!$existing) {
            return academic_api_response::for_external(academic_api_response::fail_field('id', 'Course unit not found'));
        }
        $targetcourse = $courseid > 0 ? $courseid : (int)$existing['courseid'];
        academic_auth_helper::require_course_unit_write($targetcourse);
        $input = [];
        if ($code !== '') { $input['code'] = $code; }
        if ($name !== '') { $input['name'] = $name; }
        if ($updatedescription) { $input['description'] = $description; }
        if ($programmeid > 0) { $input['programmeid'] = $programmeid; }
        if ($curriculumid > 0) { $input['curriculumid'] = $curriculumid; }
        if ($yearofstudy > 0) { $input['yearofstudy'] = $yearofstudy; }
        if ($semestersequence > 0) { $input['semestersequence'] = $semestersequence; }
        if ($courseid > 0) { $input['courseid'] = $courseid; }
        if ($sectionid > 0) { $input['sectionid'] = $sectionid; }
        if ($credithours > 0) { $input['credithours'] = $credithours; }
        if ($unittype !== '') { $input['unittype'] = $unittype; }
        if ($status !== '') { $input['status'] = $status; }
        if ($isrequired === 0 || $isrequired === 1) { $input['isrequired'] = $isrequired; }
        $result = academic_course_unit_helper::update($id, $input, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_course_unit_helper::structure_keys());
    }
}
