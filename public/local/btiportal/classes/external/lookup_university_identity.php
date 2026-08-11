<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\identity_helper;

/**
 * Look up an existing Moodle identity by email, username, and/or idnumber.
 * Used by admission / lecturer / staff wizards to prevent duplicate accounts.
 */
class lookup_university_identity extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email' => new external_value(PARAM_RAW, 'Email address', VALUE_DEFAULT, ''),
            'username' => new external_value(PARAM_RAW, 'Moodle username', VALUE_DEFAULT, ''),
            'idnumber' => new external_value(PARAM_RAW, 'Admission or employee/staff number', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $email = '', string $username = '', string $idnumber = ''): array {
        self::validate_parameters(self::execute_parameters(), [
            'email' => $email,
            'username' => $username,
            'idnumber' => $idnumber,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);

        $matches = identity_helper::lookup($email, $username, $idnumber);

        return [
            'found' => !empty($matches),
            'matchcount' => count($matches),
            'matches' => $matches,
        ];
    }

    public static function execute_returns(): external_single_structure {
        $match = new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'username' => new external_value(PARAM_RAW, 'Username'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'email' => new external_value(PARAM_RAW, 'Email'),
            'idnumber' => new external_value(PARAM_RAW, 'idnumber / admission / staff number'),
            'phone1' => new external_value(PARAM_RAW, 'Phone'),
            'suspended' => new external_value(PARAM_BOOL, 'Suspended'),
            'matchfields' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Field that matched'),
                'Which identity fields matched'
            ),
            'hasstudentprofile' => new external_value(PARAM_BOOL, 'Has local_btiportal student profile'),
            'admissionstatus' => new external_value(PARAM_ALPHANUMEXT, 'Admission status'),
            'academicstatus' => new external_value(PARAM_ALPHANUMEXT, 'Academic status'),
            'programmeid' => new external_value(PARAM_INT, 'Programme id'),
            'programmename' => new external_value(PARAM_TEXT, 'Programme name'),
            'departmentid' => new external_value(PARAM_INT, 'Department id'),
            'departmentname' => new external_value(PARAM_TEXT, 'Department name'),
            'schoolid' => new external_value(PARAM_INT, 'School id'),
            'schoolname' => new external_value(PARAM_TEXT, 'School name'),
            'forcepasswordchange' => new external_value(PARAM_BOOL, 'Must change password'),
        ]);

        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'At least one match'),
            'matchcount' => new external_value(PARAM_INT, 'Number of matches'),
            'matches' => new external_multiple_structure($match, 'Matching Moodle users'),
        ]);
    }
}
