<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * Create / locate a general discussion forum for a course.
 */
class forum_helper {
    public const GENERAL_FORUM_NAME = 'General Discussion';

    /**
     * @return int forum instance id
     */
    public static function ensure_general_forum(int $courseid): int {
        global $DB;

        $existing = $DB->get_record('forum', [
            'course' => $courseid,
            'type' => 'general',
        ], '*', IGNORE_MULTIPLE);

        if ($existing) {
            return (int) $existing->id;
        }

        $course = get_course($courseid);
        $module = $DB->get_record('modules', ['name' => 'forum'], '*', MUST_EXIST);

        $data = new \stdClass();
        $data->course = $courseid;
        $data->modulename = 'forum';
        $data->module = (int) $module->id;
        $data->name = self::GENERAL_FORUM_NAME;
        $data->intro = '<p>Course discussions for students and instructors.</p>';
        $data->introformat = FORMAT_HTML;
        $data->type = 'general';
        $data->assessed = 0;
        $data->scale = 0;
        $data->forceextend = 0;
        $data->trackingtype = FORUM_TRACKING_OPTIONAL;
        $data->section = 0;
        $data->visible = 1;
        $data->visibleoncoursepage = 1;
        $data->cmidnumber = '';
        $data->groupmode = 0;
        $data->groupingid = 0;
        $data->availability = null;
        $data->completion = COMPLETION_TRACKING_NONE;

        $cm = add_moduleinfo($data, $course);
        if (empty($cm->instance)) {
            throw new \moodle_exception('error', 'error', '', null, 'Failed to create general forum');
        }
        return (int) $cm->instance;
    }
}
