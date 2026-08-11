<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Create Moodle assignments for portal "document assignment" flow.
 */
class assignment_helper {
    /**
     * Create a file-submission assign in a course section and map its grade item to the unit.
     *
     * @param int $introattachments Optional draft area item id (from webservice/upload.php)
     *        saved into mod_assign introattachment filearea — same path as Moodle's introattachments.
     *
     * @return array{
     *   courseid:int, unitid:int, assignmentid:int, cmid:int, name:string,
     *   intro:string, gradeitemid:int, grademax:float, mapped:bool
     * }
     */
    public static function create_document_assignment(
        int $courseid,
        int $unitid,
        string $name,
        string $intro = '',
        float $grademax = 100.0,
        int $introattachments = 0,
        string $category = '',
        int $allowsubmissionsfromdate = 0,
        int $duedate = 0,
        int $cutoffdate = 0
    ): array {
        global $DB;

        $category = assignment_meta_helper::normalize_category($category);

        $course = get_course($courseid);
        $section = $DB->get_record('course_sections', [
            'id' => $unitid,
            'course' => $courseid,
        ], '*', MUST_EXIST);

        $name = trim($name);
        if ($name === '') {
            throw new \moodle_exception('invalidname', 'error');
        }

        $introtrim = trim($intro);
        if ($introtrim === '') {
            $introhtml = '<p></p>';
        } else if (strpos($introtrim, '<') === false) {
            $introhtml = '<p>' . s($introtrim) . '</p>';
        } else {
            $introhtml = clean_text($introtrim, FORMAT_HTML);
        }

        $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);
        $sectionnum = (int)$section->section;
        if ($grademax <= 0) {
            $grademax = 100.0;
        }

        $allowfrom = max(0, $allowsubmissionsfromdate);
        $due = max(0, $duedate);
        $cutoff = max(0, $cutoffdate);
        if ($cutoff <= 0 && $due > 0) {
            $cutoff = $due;
        }
        if ($due > 0 && $allowfrom > 0 && $due < $allowfrom) {
            throw new \invalid_parameter_exception('Due time must be after the open (start) time');
        }
        if ($cutoff > 0 && $allowfrom > 0 && $cutoff < $allowfrom) {
            throw new \invalid_parameter_exception('Close time must be after the open (start) time');
        }

        $fromform = (object)[
            'name' => $name,
            'course' => $courseid,
            'section' => $sectionnum,
            'module' => (int)$module->id,
            'modulename' => 'assign',
            'instance' => 0,
            'intro' => $introhtml,
            'introformat' => FORMAT_HTML,
            'alwaysshowdescription' => 1,
            'submissiondrafts' => 0,
            'requiresubmissionstatement' => 0,
            'sendnotifications' => 0,
            'sendlatenotifications' => 0,
            'sendstudentnotifications' => 0,
            'duedate' => $due,
            'allowsubmissionsfromdate' => $allowfrom,
            'grade' => $grademax,
            'cutoffdate' => $cutoff,
            'gradingduedate' => 0,
            'teamsubmission' => 0,
            'requireallteammemberssubmit' => 0,
            'teamsubmissiongroupingid' => 0,
            'blindmarking' => 0,
            'hidegrader' => 0,
            'revealidents' => 0,
            'attemptreopenmethod' => 'none',
            'maxattempts' => 1,
            'markingworkflow' => 0,
            'markingallocation' => 0,
            // File submissions only (brief lives in intro + optional introattachments).
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 20,
            'assignsubmission_file_maxsizebytes' => 0,
            'assignfeedback_comments_enabled' => 1,
            'submissionattachments' => 0,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
            'completionexpected' => 0,
            'showdescription' => 1,
            'availabilityconditionsjson' => '',
        ];

        // Native Moodle field: assign::save_intro_draft_files() reads introattachments draft id.
        if ($introattachments > 0) {
            $fromform->introattachments = $introattachments;
        }

        $cm = add_moduleinfo($fromform, $course);
        $cmid = (int)($cm->coursemodule ?? 0);
        $assignmentid = (int)($cm->instance ?? 0);
        if ($cmid <= 0 || $assignmentid <= 0) {
            throw new \moodle_exception('error', 'error', '', null, 'Failed to create Moodle assignment');
        }

        // Ensure the CM is on the intended section (add_moduleinfo uses section number).
        $cmrec = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        if ((int)$cmrec->section !== (int)$section->id) {
            $DB->set_field('course_modules', 'section', $section->id, ['id' => $cmid]);
            rebuild_course_cache($courseid, true);
        }

        $gi = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignmentid,
            'itemnumber' => 0,
        ], '*', MUST_EXIST);

        $mapping = gradebook_helper::map_grade_item($courseid, $unitid, (int)$gi->id);
        assignment_meta_helper::save($assignmentid, $courseid, $unitid, $category);

        return [
            'courseid' => $courseid,
            'unitid' => $unitid,
            'assignmentid' => $assignmentid,
            'cmid' => $cmid,
            'name' => $name,
            'intro' => $introhtml,
            'gradeitemid' => (int)$mapping['gradeitemid'],
            'grademax' => (float)$gi->grademax,
            'mapped' => true,
            'category' => $category,
            'allowsubmissionsfromdate' => $allowfrom,
            'duedate' => $due,
            'cutoffdate' => $cutoff,
        ];
    }
}
