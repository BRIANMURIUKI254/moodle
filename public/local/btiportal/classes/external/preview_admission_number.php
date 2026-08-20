<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\admission_number_helper;

/**
 * Read-only preview of the next BTI/YYYY/##### admission number.
 * Does not reserve or increment a sequence.
 */
class preview_admission_number extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);

        return admission_number_helper::preview();
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'admissionnumber' => new external_value(PARAM_TEXT, 'Next unused admission number (not reserved)'),
            'year' => new external_value(PARAM_INT, 'Calendar year used in the prefix'),
            'sequence' => new external_value(PARAM_INT, 'Next sequence within the year'),
            'preview' => new external_value(PARAM_BOOL, 'Always true — this call does not consume a number'),
        ]);
    }
}
