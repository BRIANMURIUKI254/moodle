<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\student_portal_upsert_helper;

class upsert_student extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id', VALUE_DEFAULT, 0),
            'username' => new external_value(PARAM_USERNAME, 'Moodle username', VALUE_DEFAULT, ''),
            'admission_no' => new external_value(PARAM_TEXT, 'Admission number (user idnumber)', VALUE_DEFAULT, ''),
            'programme_id' => new external_value(PARAM_INT, 'local_btiportal_programme.id'),
            'year_of_study' => new external_value(PARAM_INT, 'Curriculum year number', VALUE_DEFAULT, 0),
            'academic_year' => new external_value(PARAM_INT, 'Institutional academic year id', VALUE_DEFAULT, 0),
            'semester' => new external_value(PARAM_INT, 'Institutional semester id', VALUE_DEFAULT, 0),
            'advisor_userid' => new external_value(PARAM_INT, 'Advisor Moodle user id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $userid = 0,
        string $username = '',
        string $admission_no = '',
        int $programme_id = 0,
        int $year_of_study = 0,
        int $academic_year = 0,
        int $semester = 0,
        int $advisor_userid = 0
    ): array {
        global $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/btiportal:managestudentfees', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'username' => $username,
            'admission_no' => $admission_no,
            'programme_id' => $programme_id,
            'year_of_study' => $year_of_study,
            'academic_year' => $academic_year,
            'semester' => $semester,
            'advisor_userid' => $advisor_userid,
        ]);

        $result = student_portal_upsert_helper::upsert([
            'userid' => $params['userid'],
            'username' => $params['username'],
            'admission_no' => $params['admission_no'],
            'programme_id' => $params['programme_id'],
            'year_of_study' => $params['year_of_study'],
            'academic_year' => $params['academic_year'],
            'semester' => $params['semester'],
            'advisor_userid' => $params['advisor_userid'],
        ], (int)$USER->id);

        if (!$result['ok']) {
            throw new \invalid_parameter_exception(
                $result['errors'][0]['message'] ?? 'Upsert failed'
            );
        }

        return [
            'ok' => true,
            'userid' => (int)$result['userid'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }
}