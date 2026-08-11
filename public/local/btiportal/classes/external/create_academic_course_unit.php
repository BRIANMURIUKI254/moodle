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

class create_academic_course_unit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_TEXT, 'Code'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'yearofstudy' => new external_value(PARAM_INT, 'Year of study'),
            'semestersequence' => new external_value(PARAM_INT, 'Curriculum semester sequence'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'sectionid' => new external_value(PARAM_INT, 'Moodle section id'),
            'credithours' => new external_value(PARAM_FLOAT, 'Credit hours'),
            'unittype' => new external_value(PARAM_ALPHANUMEXT, 'core|elective|optional', VALUE_DEFAULT, 'core'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, 'draft'),
            'isrequired' => new external_value(PARAM_BOOL, 'Required flag', VALUE_DEFAULT, true),
        ]);
    }
    public static function execute(
        string $code, string $name, string $description, int $programmeid, int $curriculumid,
        int $yearofstudy, int $semestersequence, int $courseid, int $sectionid, float $credithours,
        string $unittype = 'core', string $status = 'draft', bool $isrequired = true
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'code', 'name', 'description', 'programmeid', 'curriculumid', 'yearofstudy',
            'semestersequence', 'courseid', 'sectionid', 'credithours', 'unittype', 'status', 'isrequired'
        ));
        academic_auth_helper::require_course_unit_write((int)$params['courseid']);
        $result = academic_course_unit_helper::create($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(academic_course_unit_helper::structure_keys());
    }
}
