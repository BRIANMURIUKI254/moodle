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
use local_btiportal\programme_container_helper;

class get_programme_container_mappings extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        academic_auth_helper::require_manage_academic();
        $items = [];
        $programmes = $DB->get_records('local_btiportal_programme', null, 'id ASC', 'id,code,name,containercourseid');
        foreach ($programmes as $programme) {
            $courseid = (int)$programme->containercourseid;
            $reason = $courseid > 1 ? 'mapped' : 'unmapped';
            if ($courseid <= 1) {
                $course = programme_container_helper::find_course_by_shortname((string)$programme->code);
                if (!$course) {
                    $reason = 'no_moodle_course';
                } else if (programme_container_helper::is_unit_moodle_course((int)$course->id)) {
                    $reason = 'shortname_matches_a_unit_course';
                    $courseid = (int)$course->id;
                }
            }
            $items[] = [
                'programmeid' => (int)$programme->id,
                'code' => (string)$programme->code,
                'name' => (string)$programme->name,
                'courseid' => $courseid,
                'reason' => $reason,
            ];
        }
        return academic_api_response::for_external(academic_api_response::ok([
            'items' => $items,
            'count' => count($items),
        ]));
    }

    public static function execute_returns(): external_single_structure {
        $row = [
            'programmeid' => new external_value(PARAM_INT, 'programmeid'),
            'code' => new external_value(PARAM_TEXT, 'code'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'courseid' => new external_value(PARAM_INT, 'courseid'),
            'reason' => new external_value(PARAM_TEXT, 'reason'),
        ];
        return academic_api_response::returns_structure([
            'items' => new external_multiple_structure(new external_single_structure($row)),
            'count' => new external_value(PARAM_INT, 'count'),
        ]);
    }
}
