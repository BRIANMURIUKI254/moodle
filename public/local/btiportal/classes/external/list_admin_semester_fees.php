<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\semester_fees_helper;

class list_admin_semester_fees extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'academicyearid' => new external_value(PARAM_INT, 'Academic year id'),
            'semesterid' => new external_value(PARAM_INT, 'Institutional semester id'),
        ]);
    }

    public static function execute(int $programmeid, int $academicyearid, int $semesterid): array {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/btiportal:managestudentfees', $context);
        $params = self::validate_parameters(self::execute_parameters(), [
            'programmeid' => $programmeid,
            'academicyearid' => $academicyearid,
            'semesterid' => $semesterid,
        ]);
        $payload = semester_fees_helper::admin_payload(
            (int)$params['programmeid'],
            (int)$params['academicyearid'],
            (int)$params['semesterid']
        );
        if ($payload['schedule'] === null) {
            unset($payload['schedule']);
        }
        return $payload;
    }

    public static function execute_returns(): external_single_structure {
        $line = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Line id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'amount' => new external_value(PARAM_INT, 'Minor units'),
            'currency' => new external_value(PARAM_ALPHA, 'Currency'),
            'status' => new external_value(PARAM_ALPHA, 'draft|published'),
            'due_at' => new external_value(PARAM_INT, 'Due unix'),
        ]);
        $course = new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'coursename' => new external_value(PARAM_TEXT, 'Name'),
            'code' => new external_value(PARAM_TEXT, 'Unit code'),
        ]);
        $coursefee = new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'code' => new external_value(PARAM_TEXT, 'Unit code'),
            'coursename' => new external_value(PARAM_TEXT, 'Name'),
            'amount' => new external_value(PARAM_INT, 'Minor units'),
            'currency' => new external_value(PARAM_ALPHA, 'Currency'),
            'status' => new external_value(PARAM_TEXT, 'Line status'),
            'line_id' => new external_value(PARAM_INT, 'Fee line id'),
            'editable' => new external_value(PARAM_BOOL, 'Can edit amount'),
        ]);
        $schedule = new external_single_structure([
            'amount' => new external_value(PARAM_INT, 'Minor units'),
            'currency' => new external_value(PARAM_ALPHA, 'Currency'),
            'due_at' => new external_value(PARAM_INT, 'Due unix'),
            'status' => new external_value(PARAM_ALPHA, 'draft|published'),
        ]);
        return new external_single_structure([
            'schedule' => $schedule,
            'lines' => new external_multiple_structure($line),
            'draft_lines_total' => new external_value(PARAM_INT, 'Draft line sum minor'),
            'suggested_courses' => new external_multiple_structure($course),
            'courses' => new external_multiple_structure($coursefee, 'Per-course fees for semester'),
        ], 'Admin semester fee payload');
    }
}