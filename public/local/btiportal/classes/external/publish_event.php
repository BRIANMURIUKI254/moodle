<?php
namespace local_btiportal\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_btiportal\content_helper;
use local_btiportal\department_auth_helper;

class publish_event extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id (1 or 0 = site-wide)', VALUE_DEFAULT, 0),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'body' => new external_value(PARAM_RAW, 'Body'),
            'timestart' => new external_value(PARAM_INT, 'Unix start time'),
            'timeduration' => new external_value(PARAM_INT, 'Duration seconds', VALUE_DEFAULT, 0),
            'draftitemid' => new external_value(PARAM_INT, 'Draft image item id', VALUE_DEFAULT, 0),
            'scope' => new external_value(PARAM_ALPHA, 'university|department|course', VALUE_DEFAULT, 'course'),
            'departmentid' => new external_value(PARAM_INT, 'Department id when scope=department', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $courseid = 0,
        string $title = '',
        string $body = '',
        int $timestart = 0,
        int $timeduration = 0,
        int $draftitemid = 0,
        string $scope = 'course',
        int $departmentid = 0
    ): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'title' => $title,
            'body' => $body,
            'timestart' => $timestart,
            'timeduration' => $timeduration,
            'draftitemid' => $draftitemid,
            'scope' => $scope,
            'departmentid' => $departmentid,
        ]);

        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('noguest');
        }

        if (trim($title) === '' || trim($body) === '') {
            throw new \invalid_parameter_exception('Title and message are required');
        }

        $scope = content_helper::normalize_content_scope($scope, $courseid, $departmentid);
        $userid = (int)$USER->id;

        if ($scope === 'university') {
            department_auth_helper::require_operational_access(null, '', false);
            if (!department_auth_helper::is_university_admin($userid)) {
                throw new \moodle_exception('nopermissions', 'error', '', 'publish university event');
            }
            $courseid = (int)SITEID;
            $departmentid = 0;
        } else if ($scope === 'department') {
            if ($departmentid <= 0) {
                throw new \invalid_parameter_exception('departmentid is required for department scope');
            }
            department_auth_helper::require_operational_access(
                $departmentid,
                'local/btiportal:managedepartmentcalendar',
                true
            );
            $courseid = (int)SITEID;
        } else {
            if ($courseid <= 1) {
                // Legacy "all"/site course publish path for admins with calendar manage.
                self::validate_context(\context_system::instance());
                if (!is_siteadmin() && !has_capability('moodle/site:config', \context_system::instance())) {
                    if (!has_capability('moodle/calendar:manageentries', \context_system::instance())) {
                        throw new \moodle_exception('nopermissions', 'error', '', 'publish site event');
                    }
                }
                $courseid = (int)SITEID;
                $scope = 'university';
                $departmentid = 0;
            } else {
                self::validate_context(\context_course::instance($courseid));
                if (!content_helper::user_can_manage_course($userid, $courseid) && !is_siteadmin()) {
                    throw new \moodle_exception('nopermissions', 'error', '', 'publish event');
                }
                $departmentid = 0;
            }
        }

        return content_helper::publish_event(
            $courseid,
            $userid,
            trim($title),
            trim($body),
            $timestart,
            max(0, $timeduration),
            $draftitemid,
            $scope,
            $departmentid
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'coursecode' => new external_value(PARAM_TEXT, 'Course code'),
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'authorid' => new external_value(PARAM_INT, 'Author id'),
            'authorname' => new external_value(PARAM_TEXT, 'Author name'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'body' => new external_value(PARAM_RAW, 'Body'),
            'timestart' => new external_value(PARAM_INT, 'Start'),
            'timeduration' => new external_value(PARAM_INT, 'Duration'),
            'eventid' => new external_value(PARAM_INT, 'Calendar event id'),
            'imageurl' => new external_value(PARAM_RAW, 'Image URL'),
            'filename' => new external_value(PARAM_FILE, 'Image file name'),
            'timecreated' => new external_value(PARAM_INT, 'Created'),
            'departmentid' => new external_value(PARAM_INT, 'Department id'),
            'departmentcode' => new external_value(PARAM_TEXT, 'Department code'),
            'departmentname' => new external_value(PARAM_TEXT, 'Department name'),
            'scope' => new external_value(PARAM_TEXT, 'Publication scope'),
            'status' => new external_value(PARAM_TEXT, 'Publication status'),
        ]);
    }
}
