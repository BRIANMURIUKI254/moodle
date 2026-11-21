<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\academic_auth_helper;
use local_btiportal\student_context_helper;

class get_student_context extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        $userid = academic_auth_helper::resolve_student_userid_for_read(0);
        return student_context_helper::get_for_user($userid);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'profile' => new external_single_structure([
                'admission_no' => new external_value(PARAM_TEXT, 'Admission number', VALUE_OPTIONAL),
                'programme' => new external_value(PARAM_TEXT, 'Programme', VALUE_OPTIONAL),
                'school' => new external_value(PARAM_TEXT, 'School', VALUE_OPTIONAL),
                'department' => new external_value(PARAM_TEXT, 'Department', VALUE_OPTIONAL),
                'year_of_study' => new external_value(PARAM_TEXT, 'Year of study', VALUE_OPTIONAL),
                'academic_year' => new external_value(PARAM_TEXT, 'Academic year', VALUE_OPTIONAL),
                'semester' => new external_value(PARAM_TEXT, 'Semester', VALUE_OPTIONAL),
                'advisor' => new external_value(PARAM_TEXT, 'Academic advisor', VALUE_OPTIONAL),
            ]),
            'fees' => new external_single_structure([
                'status' => new external_value(PARAM_ALPHA, 'unpaid|paid'),
                'paid_at' => new external_value(PARAM_INT, 'Paid unix time', VALUE_OPTIONAL),
                'amount' => new external_value(PARAM_INT, 'Tuition amount minor units', VALUE_OPTIONAL),
                'currency' => new external_value(PARAM_ALPHA, 'Currency code', VALUE_OPTIONAL),
                'amount_paid' => new external_value(PARAM_INT, 'Amount paid in minor units', VALUE_DEFAULT, 0),
                'amount_paid_currency' => new external_value(PARAM_ALPHA, 'Paid amount currency', VALUE_DEFAULT, 'KES'),
            ]),
            'access' => new external_single_structure([
                'allowed' => new external_value(PARAM_BOOL, 'Portal learning access'),
                'reason' => new external_value(PARAM_ALPHANUMEXT, 'FEES_UNPAID|null', VALUE_OPTIONAL),
            ]),
        ]);
    }
}