<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\admission_mailer;

/**
 * Send a formal, personalized admission welcome email (portal login, username, temporary password).
 */
class send_admission_welcome_email extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id of the admitted student'),
            'programmename' => new external_value(PARAM_TEXT, 'Programme name to show in the email', VALUE_DEFAULT, ''),
            'portalurl' => new external_value(PARAM_RAW_TRIMMED, 'Public B TI LMS origin or login URL', VALUE_DEFAULT, ''),
            'includepassword' => new external_value(PARAM_BOOL, 'Generate and email a temporary password', VALUE_DEFAULT, true),
            'admissionnumber' => new external_value(PARAM_TEXT, 'Admission / student number', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $userid,
        string $programmename = '',
        string $portalurl = '',
        bool $includepassword = true,
        string $admissionnumber = ''
    ): array {
        self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'programmename' => $programmename,
            'portalurl' => $portalurl,
            'includepassword' => $includepassword,
            'admissionnumber' => $admissionnumber,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);

        return admission_mailer::send($userid, [
            'programmename' => $programmename,
            'portalurl' => $portalurl,
            'includepassword' => $includepassword,
            'admissionnumber' => $admissionnumber,
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sent' => new external_value(PARAM_BOOL, 'Whether the email was sent'),
            'userid' => new external_value(PARAM_INT, 'Student user id'),
            'includepassword' => new external_value(PARAM_BOOL, 'Whether a temporary password was generated and emailed'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
