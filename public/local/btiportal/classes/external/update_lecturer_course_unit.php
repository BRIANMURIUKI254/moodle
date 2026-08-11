<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use local_btiportal\academic_api_response;
use local_btiportal\academic_auth_helper;
use local_btiportal\course_unit_teacher_helper;
use local_btiportal\lecturer_teaching_helper;

class update_lecturer_course_unit extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Assignment id'),
            'teachingrole' => new external_value(PARAM_ALPHANUMEXT, 'Teaching role', VALUE_DEFAULT, ''),
            'isprimary' => new external_value(PARAM_BOOL, 'Primary lecturer', VALUE_DEFAULT, false),
            'updateisprimary' => new external_value(PARAM_BOOL, 'Whether isprimary was supplied', VALUE_DEFAULT, false),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $id,
        string $teachingrole = '',
        bool $isprimary = false,
        bool $updateisprimary = false,
        string $status = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'teachingrole' => $teachingrole,
            'isprimary' => $isprimary,
            'updateisprimary' => $updateisprimary,
            'status' => $status,
        ]);
        academic_auth_helper::require_manage_academic();
        $input = [];
        if ($params['teachingrole'] !== '') {
            $input['teachingrole'] = $params['teachingrole'];
        }
        if ($params['updateisprimary']) {
            $input['isprimary'] = $params['isprimary'];
        }
        if ($params['status'] !== '') {
            $input['status'] = $params['status'];
        }
        $result = course_unit_teacher_helper::update((int)$params['id'], $input);
        if (!$result['ok']) {
            return academic_api_response::for_external(academic_api_response::fail($result['errors']));
        }
        $items = lecturer_teaching_helper::list_course_units((int)$result['record']['userid'], 'all');
        foreach ($items as $item) {
            if ((int)$item['id'] === (int)$result['record']['id']) {
                return academic_api_response::for_external(academic_api_response::ok($item));
            }
        }
        return academic_api_response::for_external(academic_api_response::ok($result['record']));
    }

    public static function execute_returns() {
        return academic_api_response::returns_structure(
            lecturer_teaching_helper::course_unit_assignment_structure_keys()
        );
    }
}
