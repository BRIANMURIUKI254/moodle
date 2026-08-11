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
use local_btiportal\academic_delivery_compare_helper;
use local_btiportal\academic_enrolment_helper;
use local_btiportal\enrolment_reconciliation_helper;

class reconcile_student_enrolments extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'operationtype' => new external_value(PARAM_ALPHANUMEXT, 'Operation type', VALUE_DEFAULT, 'manual_reconciliation'),
            'curriculumid' => new external_value(PARAM_INT, 'Target curriculum id for compare preview', VALUE_DEFAULT, 0),
            'schoolid' => new external_value(PARAM_INT, 'Target school id', VALUE_DEFAULT, 0),
            'departmentid' => new external_value(PARAM_INT, 'Target department id', VALUE_DEFAULT, 0),
            'programmeid' => new external_value(PARAM_INT, 'Target programme id', VALUE_DEFAULT, 0),
            'curriculumyearid' => new external_value(PARAM_INT, 'Target curriculum year id', VALUE_DEFAULT, 0),
            'curriculumsemesterid' => new external_value(PARAM_INT, 'Target curriculum semester id', VALUE_DEFAULT, 0),
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
        string $operationtype = 'manual_reconciliation',
        int $curriculumid = 0,
        int $schoolid = 0,
        int $departmentid = 0,
        int $programmeid = 0,
        int $curriculumyearid = 0,
        int $curriculumsemesterid = 0,
        int $academicyearid = 0,
        int $semesterid = 0,
        string $policy = 'preserve_and_enrol_new'
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'operationtype', 'curriculumid', 'schoolid', 'departmentid', 'programmeid',
            'curriculumyearid', 'curriculumsemesterid', 'academicyearid', 'semesterid', 'policy'
        ));
        academic_auth_helper::require_view_academic();

        if ((int)$params['curriculumid'] > 0) {
            $result = enrolment_reconciliation_helper::preview_with_compare(
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
                $params['policy'],
                $params['operationtype']
            );
            if (!$result['ok']) {
                return academic_api_response::for_external(academic_api_response::fail($result['errors']));
            }
            $preview = $result['preview'];
            if (($preview['enrolment'] ?? null) === null) {
                unset($preview['enrolment']);
            }
            return academic_api_response::for_external(academic_api_response::ok($preview));
        }

        $result = enrolment_reconciliation_helper::preview(
            (int)$params['userid'],
            $params['operationtype']
        );
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        $payload = [
            'userid' => (int)$params['userid'],
            'operationtype' => $params['operationtype'],
            'policy' => $params['policy'],
            'enrolment' => $result['preview'],
            'enrolmenterrors' => [],
        ];
        return academic_api_response::for_external(academic_api_response::ok($payload));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'userid' => new external_value(PARAM_INT, 'userid'),
            'operationtype' => new external_value(PARAM_ALPHANUMEXT, 'operationtype'),
            'policy' => new external_value(PARAM_ALPHANUMEXT, 'policy'),
            'comparison' => new external_single_structure(
                academic_delivery_compare_helper::comparison_structure_keys(),
                'comparison',
                VALUE_OPTIONAL
            ),
            'enrolment' => new external_single_structure(
                academic_enrolment_helper::preview_structure_keys(),
                'enrolment',
                VALUE_OPTIONAL
            ),
            'enrolmenterrors' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'field'),
                    'message' => new external_value(PARAM_TEXT, 'message'),
                ]),
                'enrolmenterrors',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
