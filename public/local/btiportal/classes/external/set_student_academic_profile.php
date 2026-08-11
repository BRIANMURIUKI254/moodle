<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_admission_helper;
use local_btiportal\student_profile_helper;

/**
 * Attaches an academic placement to a Moodle user the portal already created.
 * Never creates or modifies the Moodle account itself.
 */
class set_student_academic_profile extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Existing Moodle user id'),
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'curriculumid' => new external_value(PARAM_INT, 'Curriculum id'),
            'schoolid' => new external_value(PARAM_INT, 'School id (0 to infer)', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Department id (0 to infer)', VALUE_DEFAULT, 0),
            'curriculumyearid' => new external_value(PARAM_INT, 'Year of study id', VALUE_DEFAULT, 0),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Curriculum semester id', VALUE_DEFAULT, 0),
            'academicyearid' => new external_value(PARAM_INT, 'Institutional academic year id', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id', VALUE_DEFAULT, 0),
            'admissiondate' => new external_value(PARAM_INT, 'Admission timestamp', VALUE_DEFAULT, 0),
            'admissionstatus' => new external_value(PARAM_ALPHANUMEXT, 'Admission status', VALUE_DEFAULT, 'active'),
            'academicstatus' => new external_value(PARAM_ALPHANUMEXT, 'Academic status', VALUE_DEFAULT, 'active'),
            'expectedcompletion' => new external_value(PARAM_INT, 'Expected completion timestamp', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Record status', VALUE_DEFAULT, 'active'),
            'entrytype' => new external_value(PARAM_ALPHANUMEXT, 'Entry type', VALUE_DEFAULT, ''),
            'studymode' => new external_value(PARAM_ALPHANUMEXT, 'Study mode', VALUE_DEFAULT, ''),
            'deliverymode' => new external_value(PARAM_ALPHANUMEXT, 'Delivery mode', VALUE_DEFAULT, ''),
            'campus' => new external_value(PARAM_TEXT, 'Campus', VALUE_DEFAULT, ''),
            'intake' => new external_value(PARAM_TEXT, 'Intake', VALUE_DEFAULT, ''),
            'scholarship' => new external_value(PARAM_TEXT, 'Scholarship', VALUE_DEFAULT, ''),
            'sponsor' => new external_value(PARAM_TEXT, 'Sponsor', VALUE_DEFAULT, ''),
        ]);
    }
    public static function execute(
        int $userid, int $programmeid, int $curriculumid, int $schoolid = 0, int $departmentid = 0,
        int $curriculumyearid = 0, int $curriculumsemesterid = 0, int $academicyearid = 0,
        int $semesterid = 0, int $admissiondate = 0, string $admissionstatus = 'active',
        string $academicstatus = 'active', int $expectedcompletion = 0, string $status = 'active',
        string $entrytype = '', string $studymode = '', string $deliverymode = '',
        string $campus = '', string $intake = '', string $scholarship = '', string $sponsor = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid, 'programmeid' => $programmeid, 'curriculumid' => $curriculumid,
            'schoolid' => $schoolid, 'departmentid' => $departmentid,
            'curriculumyearid' => $curriculumyearid, 'curriculumsemesterid' => $curriculumsemesterid,
            'academicyearid' => $academicyearid, 'semesterid' => $semesterid,
            'admissiondate' => $admissiondate, 'admissionstatus' => $admissionstatus,
            'academicstatus' => $academicstatus, 'expectedcompletion' => $expectedcompletion,
            'status' => $status,
            'entrytype' => $entrytype, 'studymode' => $studymode, 'deliverymode' => $deliverymode,
            'campus' => $campus, 'intake' => $intake, 'scholarship' => $scholarship, 'sponsor' => $sponsor,
        ]);
        academic_auth_helper::require_manage_academic();
        // Omit empty placement meta so updates do not wipe previously stored values.
        foreach (['entrytype', 'studymode', 'deliverymode', 'campus', 'intake', 'scholarship', 'sponsor'] as $key) {
            if (!array_key_exists($key, $params) || $params[$key] === '') {
                unset($params[$key]);
            }
        }
        $result = student_admission_helper::admit_student($userid, $params, (int)$USER->id);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }
    public static function execute_returns() {
        return academic_api_response::returns_structure(student_profile_helper::structure_keys());
    }
}
