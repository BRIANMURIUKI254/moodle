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
            'userid' => new external_value(PARAM_INT, 'Student Moodle user id'),
            'source' => new external_value(PARAM_ALPHA, 'manual|paystack|migration', VALUE_DEFAULT, 'manual'),
            'reference' => new external_value(PARAM_TEXT, 'Payment reference', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $userid, string $source = 'manual', string $reference = ''): array {
        global $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/btiportal:managestudentfees', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'source' => $source,
            'reference' => $reference,
        ]);

        $result = student_fees_helper::mark_paid_and_enrol(
            (int)$params['userid'],
            $params['source'],
            $params['reference'],
            (int)$USER->id
        );
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