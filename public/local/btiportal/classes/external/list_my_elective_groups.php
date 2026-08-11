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
use local_btiportal\student_profile_helper;

/** Elective groups for the signed-in student's curriculum placement. */
class list_my_elective_groups extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        self::validate_parameters(self::execute_parameters(), []);
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile || (int)$profile['curriculumid'] <= 0) {
            return academic_api_response::for_external(academic_api_response::ok([
                'items' => [],
                'count' => 0,
            ]));
        }
        $yearnumber = 0;
        $semesternumber = 0;
        if ((int)$profile['curriculumyearid'] > 0) {
            $y = $DB->get_record('local_btiportal_curriculum_year', ['id' => (int)$profile['curriculumyearid']]);
            $yearnumber = $y ? (int)$y->yearnumber : 0;
        }
        if ((int)$profile['curriculumsemesterid'] > 0) {
            $s = $DB->get_record('local_btiportal_curriculum_semester', ['id' => (int)$profile['curriculumsemesterid']]);
            $semesternumber = $s ? (int)$s->semesternumber : 0;
        }
        $items = elective_helper::list_groups(
            (int)$profile['curriculumid'],
            $yearnumber,
            $semesternumber,
            'active'
        );
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(
                new external_single_structure(elective_helper::group_structure_keys())
            ),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
