<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\student_fees_helper;

class set_fees_paid extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id (legacy)', VALUE_DEFAULT, 0),
            'username' => new external_value(PARAM_USERNAME, 'Student username', VALUE_DEFAULT, ''),
            'source' => new external_value(PARAM_ALPHA, 'manual|paystack|migration', VALUE_DEFAULT, 'manual'),
            'reference' => new external_value(PARAM_TEXT, 'Payment reference', VALUE_DEFAULT, ''),
            'academicyearid' => new external_value(PARAM_INT, 'Institutional year (optional)', VALUE_DEFAULT, 0),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester (optional)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $userid = 0,
        string $username = '',
        string $source = 'manual',
        string $reference = '',
        int $academicyearid = 0,
        int $semesterid = 0
    ): array {
        global $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/btiportal:managestudentfees', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'username' => $username,
            'source' => $source,
            'reference' => $reference,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ]);

        $targetuserid = (int)$params['userid'];
        $uname = trim((string)$params['username']);
        if ($uname !== '') {
            $targetuserid = student_fees_helper::resolve_userid_by_username($uname);
            if ($targetuserid <= 0) {
                throw new \invalid_parameter_exception('Unknown username: ' . $uname);
            }
        } else if ($targetuserid <= 0) {
            throw new \invalid_parameter_exception('Username is required');
        }

        if ((int)$params['academicyearid'] > 0 && (int)$params['semesterid'] > 0) {
            $result = student_fees_helper::mark_semester_paid_manual(
                $targetuserid,
                (int)$params['academicyearid'],
                (int)$params['semesterid'],
                $params['source'],
                $params['reference'],
                (int)$USER->id
            );
        } else {
            $result = student_fees_helper::mark_paid_and_enrol(
                $targetuserid,
                $params['source'],
                $params['reference'],
                (int)$USER->id
            );
        }
        if (!$result['ok']) {
            throw new \invalid_parameter_exception($result['errors'][0]['message'] ?? 'Could not mark fees paid');
        }

        return [
            'ok' => true,
            'newly_paid' => $result['paid'] ? 1 : 0,
            'enrolled' => (int)$result['enrolled'],
            'reused' => (int)$result['reused'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'newly_paid' => new external_value(PARAM_INT, '1 if this call marked paid'),
            'enrolled' => new external_value(PARAM_INT, 'New enrolments'),
            'reused' => new external_value(PARAM_INT, 'Existing enrolments reused'),
        ]);
    }
}