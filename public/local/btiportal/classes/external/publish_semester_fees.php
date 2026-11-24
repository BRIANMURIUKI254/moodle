<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\semester_fees_helper;

class publish_semester_fees extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id'),
            'semesterid' => new external_value(PARAM_INT, 'Semester id'),
            'fee_amount' => new external_value(PARAM_TEXT, 'USD decimal amount e.g. 450.00'),
            'due_at' => new external_value(PARAM_INT, 'Due unix', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $programmeid,
        int $academicyearid,
        int $semesterid,
        string $fee_amount,
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
            'fee_amount' => $fee_amount,
            'due_at' => $due_at,
        ]);
        $result = semester_fees_helper::publish(
            (int)$params['programmeid'],
            (int)$params['academicyearid'],
            (int)$params['semesterid'],
            (string)$params['fee_amount'],
            (int)$params['due_at'],
            (int)$USER->id
        );
        if (!$result['ok']) {
            throw new \invalid_parameter_exception($result['errors'][0]['message'] ?? 'Publish failed');
        }
        return [
            'ok' => true,
            'published' => (int)($result['published'] ?? 0),
            'students' => (int)($result['students'] ?? 0),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'published' => new external_value(PARAM_INT, 'Published items'),
            'students' => new external_value(PARAM_INT, 'Student rows touched'),
        ]);
    }
}