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
use local_btiportal\elective_helper;
use local_btiportal\progression_eligibility_helper;
use local_btiportal\student_academic_history_helper;
use local_btiportal\student_delivery_plan_helper;
use local_btiportal\student_portal_helper;
use local_btiportal\student_unit_pathway_helper;
use local_btiportal\timetable_helper;

/**
 * Self-scoped Student Portal workspace for the signed-in Moodle user.
 */
class get_my_student_workspace extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'academicyearid' => new external_value(PARAM_INT, 'Filter academic year', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Filter institutional semester', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $academicyearid = 0, int $semesterid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ]);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $data = student_portal_helper::get_workspace(
            $userid,
            (int)$params['academicyearid'],
            (int)$params['semesterid']
        );
        if (empty($data['eligibility'])) {
            $data['eligibility'] = progression_eligibility_helper::empty_eligibility(
                $userid,
                'semester_progression',
                !empty($data['hasprofile'])
                    ? 'Automatic academic eligibility is not configured. Progression requires Admin approval.'
                    : 'Academic placement is not configured for your account yet.'
            );
        } else {
            $data['eligibility'] = progression_eligibility_helper::sanitize_for_external($data['eligibility']);
        }
        // Empty stub profile must include keys required by enriched_profile_structure_keys.
        if (empty($data['profile'])) {
            $data['profile'] = student_portal_helper::enrich_profile([
                'id' => 0,
                'userid' => $userid,
                'schoolid' => 0,
                'departmentid' => 0,
                'programmeid' => 0,
                'curriculumid' => 0,
                'curriculumyearid' => 0,
                'curriculumsemesterid' => 0,
                'academicyearid' => 0,
                'semesterid' => 0,
                'admissiondate' => 0,
                'admissionstatus' => 'pending',
                'academicstatus' => 'active',
                'expectedcompletion' => 0,
                'status' => 'active',
                'entrytype' => '',
                'studymode' => '',
                'deliverymode' => '',
                'campus' => '',
                'intake' => '',
                'scholarship' => '',
                'sponsor' => '',
                'timecreated' => 0,
                'timemodified' => 0,
                'usermodified' => 0,
            ]);
        }
        // deliveryplan is optional — omit key when absent so VALUE_OPTIONAL works.
        if (empty($data['deliveryplan'])) {
            unset($data['deliveryplan']);
        } else {
            $data['deliveryplan'] = student_portal_helper::sanitize_delivery_plan_for_external($data['deliveryplan']);
        }
        if (empty($data['unitpathway']) || !is_array($data['unitpathway'])) {
            $data['unitpathway'] = [
                'current' => [],
                'studied' => [],
                'upcoming' => [],
                'currentyear' => 0,
                'currentsemester' => 0,
                'advanced' => 0,
                'advancenotice' => '',
            ];
        } else {
            $data['unitpathway']['current'] = is_array($data['unitpathway']['current'] ?? null)
                ? $data['unitpathway']['current'] : [];
            $data['unitpathway']['studied'] = is_array($data['unitpathway']['studied'] ?? null)
                ? $data['unitpathway']['studied'] : [];
            $data['unitpathway']['upcoming'] = is_array($data['unitpathway']['upcoming'] ?? null)
                ? $data['unitpathway']['upcoming'] : [];
            $data['unitpathway']['currentyear'] = (int)($data['unitpathway']['currentyear'] ?? 0);
            $data['unitpathway']['currentsemester'] = (int)($data['unitpathway']['currentsemester'] ?? 0);
            $data['unitpathway']['advanced'] = (int)($data['unitpathway']['advanced'] ?? 0);
            $data['unitpathway']['advancenotice'] = (string)($data['unitpathway']['advancenotice'] ?? '');
        }
        $data['electivegroups'] = is_array($data['electivegroups'] ?? null) ? $data['electivegroups'] : [];
        $data['electiveselections'] = is_array($data['electiveselections'] ?? null) ? $data['electiveselections'] : [];
        $data['history'] = is_array($data['history'] ?? null) ? $data['history'] : [];
        $data['todaysessions'] = is_array($data['todaysessions'] ?? null) ? $data['todaysessions'] : [];
        $data['upcomingtimetablesessions'] = is_array($data['upcomingtimetablesessions'] ?? null)
            ? $data['upcomingtimetablesessions']
            : [];
        $data['fullname'] = (string)($data['fullname'] ?? '');
        $data['firstname'] = (string)($data['firstname'] ?? '');
        $data['lastname'] = (string)($data['lastname'] ?? '');
        $data['email'] = (string)($data['email'] ?? '');
        $data['username'] = (string)($data['username'] ?? '');
        $data['idnumber'] = (string)($data['idnumber'] ?? '');
        $data['phone1'] = (string)($data['phone1'] ?? '');
        $data['profileimageurl'] = (string)($data['profileimageurl'] ?? '');
        $data['hasprofile'] = (int)($data['hasprofile'] ?? 0);
        $data['historycount'] = (int)($data['historycount'] ?? count($data['history']));
        $data['academicyearid'] = (int)($data['academicyearid'] ?? 0);
        $data['semesterid'] = (int)($data['semesterid'] ?? 0);
        if (empty($data['deliverystatus']) || !is_array($data['deliverystatus'])) {
            $data['deliverystatus'] = student_portal_helper::delivery_status_from_plan(null);
        }
        return academic_api_response::for_external(academic_api_response::ok($data));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'userid' => new external_value(PARAM_INT, 'userid'),
            'fullname' => new external_value(PARAM_TEXT, 'fullname'),
            'firstname' => new external_value(PARAM_TEXT, 'firstname'),
            'lastname' => new external_value(PARAM_TEXT, 'lastname'),
            'email' => new external_value(PARAM_TEXT, 'email'),
            'username' => new external_value(PARAM_TEXT, 'username'),
            'idnumber' => new external_value(PARAM_TEXT, 'idnumber'),
            'phone1' => new external_value(PARAM_TEXT, 'phone1'),
            'profileimageurl' => new external_value(PARAM_TEXT, 'profileimageurl', VALUE_OPTIONAL),
            'hasprofile' => new external_value(PARAM_INT, 'hasprofile'),
            'profile' => new external_single_structure(student_portal_helper::enriched_profile_structure_keys()),
            'deliverystatus' => new external_single_structure(student_portal_helper::delivery_status_structure_keys()),
            'deliveryplan' => new external_single_structure(
                student_delivery_plan_helper::plan_structure_keys(),
                'deliveryplan',
                VALUE_OPTIONAL
            ),
            'unitpathway' => new external_single_structure(
                student_unit_pathway_helper::pathway_structure_keys(),
                'unitpathway',
                VALUE_OPTIONAL
            ),
            'electivegroups' => new external_multiple_structure(
                new external_single_structure(elective_helper::group_structure_keys())
            ),
            'electiveselections' => new external_multiple_structure(
                new external_single_structure(elective_helper::selection_structure_keys())
            ),
            'eligibility' => new external_single_structure(progression_eligibility_helper::eligibility_structure_keys()),
            'history' => new external_multiple_structure(
                new external_single_structure(student_academic_history_helper::structure_keys())
            ),
            'historycount' => new external_value(PARAM_INT, 'historycount'),
            'todaysessions' => new external_multiple_structure(
                new external_single_structure(timetable_helper::structure_keys())
            ),
            'upcomingtimetablesessions' => new external_multiple_structure(
                new external_single_structure(timetable_helper::structure_keys())
            ),
            'academicyearid' => new external_value(PARAM_INT, 'academicyearid'),
            'semesterid' => new external_value(PARAM_INT, 'semesterid'),
            'academicyears' => new external_multiple_structure(
                new external_single_structure(student_portal_helper::period_structure_keys())
            ),
            'semesters' => new external_multiple_structure(
                new external_single_structure(student_portal_helper::period_structure_keys())
            ),
            'currentacademicyear' => new external_single_structure(student_portal_helper::period_structure_keys()),
            'currentsemester' => new external_single_structure(student_portal_helper::period_structure_keys()),
        ]);
    }
}
