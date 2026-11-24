<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\semester_fees_helper;

class upsert_admin_semester_fee extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id'),
            'semesterid' => new external_value(PARAM_INT, 'Semester id'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'amount' => new external_value(PARAM_INT, 'Minor units'),
            'currency' => new external_value(PARAM_ALPHA, 'Currency', VALUE_DEFAULT, 'USD'),
            'due_at' => new external_value(PARAM_INT, 'Due unix', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $programmeid,
        int $academicyearid,
        int $semesterid,
        int $courseid,
        int $amount,
        string $currency = 'KES',
        int $due_at = 0
    ): array {
        global $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/btiportal:managestudentfees', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'programmeid' => $programmeid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
            'courseid' => $courseid,
            'amount' => $amount,
            'currency' => $currency,
            'due_at' => $due_at,
        ]);
        $result = semester_fees_helper::upsert_line(
            (int)$params['programmeid'],
            (int)$params['academicyearid'],
            (int)$params['semesterid'],
            (int)$params['courseid'],
            (int)$params['amount'],
            (string)$params['currency'],
            (int)$params['due_at'],
            (int)$USER->id
        );
        if (!$result['ok']) {
            throw new \invalid_parameter_exception($result['errors'][0]['message'] ?? 'Could not save fee line');
        }
        return ['ok' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Saved'),
        ]);
    }
}