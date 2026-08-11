<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\progression_eligibility_helper;

class create_progression_rule extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'actiontype' => new external_value(PARAM_ALPHANUMEXT, 'semester_progression or promotion'),
            'programmeid' => new external_value(PARAM_INT, 'Programme id', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id', VALUE_DEFAULT, 0),
            'curriculumyearid' => new external_value(PARAM_INT, 'Curriculum year id', VALUE_DEFAULT, 0),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Curriculum semester id', VALUE_DEFAULT, 0),
            'mincompletedunits' => new external_value(PARAM_INT, 'Minimum completed units', VALUE_DEFAULT, 0),
            'maxfailedunits' => new external_value(PARAM_INT, 'Maximum failed units', VALUE_DEFAULT, 999),
            'requiremanualapproval' => new external_value(PARAM_INT, 'Require manual approval 0/1', VALUE_DEFAULT, 1),
            'notes' => new external_value(PARAM_TEXT, 'Notes', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $curriculumid,
        string $actiontype,
        int $programmeid = 0,
        int $academicyearid = 0,
        int $curriculumyearid = 0,
        int $curriculumsemesterid = 0,
        int $mincompletedunits = 0,
        int $maxfailedunits = 999,
        int $requiremanualapproval = 1,
        string $notes = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'curriculumid', 'actiontype', 'programmeid', 'academicyearid',
            'curriculumyearid', 'curriculumsemesterid', 'mincompletedunits',
            'maxfailedunits', 'requiremanualapproval', 'notes'
        ));
        academic_auth_helper::require_manage_academic();
        $result = progression_eligibility_helper::create_rule($params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'curriculumid' => new external_value(PARAM_INT, 'curriculumid'),
            'programmeid' => new external_value(PARAM_INT, 'programmeid'),
            'academicyearid' => new external_value(PARAM_INT, 'academicyearid'),
            'curriculumyearid' => new external_value(PARAM_INT, 'curriculumyearid'),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'curriculumsemesterid'),
            'actiontype' => new external_value(PARAM_ALPHANUMEXT, 'actiontype'),
            'mincompletedunits' => new external_value(PARAM_INT, 'mincompletedunits'),
            'maxfailedunits' => new external_value(PARAM_INT, 'maxfailedunits'),
            'requiremanualapproval' => new external_value(PARAM_INT, 'requiremanualapproval'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'status'),
            'version' => new external_value(PARAM_INT, 'version'),
            'notes' => new external_value(PARAM_TEXT, 'notes'),
            'timecreated' => new external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new external_value(PARAM_INT, 'timemodified'),
        ]);
    }
}
