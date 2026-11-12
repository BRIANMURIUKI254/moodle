<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Assignment marking through Moodle's mod_assign grade API.
 */
class assignment_grading_helper {
    public static function assignment_belongs_to_unit(int $assignmentid, int $courseid, int $unitid): bool {
        global $DB;

        $cm = get_coursemodule_from_instance('assign', $assignmentid, $courseid, false, IGNORE_MISSING);
        if (!$cm || (int)$cm->course !== $courseid) {
            return false;
        }
        $meta = $DB->get_record('local_btiportal_assignment_meta', ['assignmentid' => $assignmentid]);
        if ($meta && (int)$meta->courseid === $courseid && (int)$meta->unitid === $unitid) {
            return true;
        }
        return (int)$cm->section === $unitid;
    }

    public static function ungraded_submission_count(int $assignmentid): int {
        global $DB;

        if ($assignmentid <= 0) {
            return 0;
        }
        return (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {assign_submission} s
              WHERE s.assignment = :assignment
                AND s.latest = 1
                AND s.status = :submitted
                AND NOT EXISTS (
                    SELECT 1
                      FROM {assign_grades} g
                     WHERE g.assignment = s.assignment
                       AND g.userid = s.userid
                       AND g.attemptnumber = s.attemptnumber
                       AND g.grade >= 0
                )",
            ['assignment' => $assignmentid, 'submitted' => 'submitted']
        );
    }

    /**
     * @return array{unchanged:bool,notified:bool,workflowstate:string}
     */
    public static function save_grade(
        int $assignmentid,
        int $studentid,
        float $grade,
        string $feedback,
        int $attemptnumber,
        string $workflowstate,
        int $actorid
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/externallib.php');

        [$assign, $cm] = self::require_grader($assignmentid, $actorid);
        $instance = $assign->get_instance();
        $resolvedattempt = self::resolve_attempt($assignmentid, $studentid, $attemptnumber);
        $current = self::current_grade($assignmentid, $studentid, $resolvedattempt);
        $currentworkflow = self::workflow_state($assignmentid, $studentid);
        $workflowstate = self::normalize_workflow((string)$instance->markingworkflow, $workflowstate, $currentworkflow);
        $samegrade = $current && abs((float)$current->grade - $grade) < 0.00001;
        $samefeedback = trim((string)($current->commenttext ?? '')) === trim($feedback);
        $sameworkflow = $workflowstate === '' || $workflowstate === $currentworkflow;
        if ($samegrade && $samefeedback && $sameworkflow) {
            return ['unchanged' => true, 'notified' => false, 'workflowstate' => $currentworkflow];
        }

        $plugindata = [];
        if ($feedback !== '') {
            $plugindata['assignfeedbackcomments_editor'] = [
                'text' => $feedback,
                'format' => FORMAT_HTML,
            ];
        }
        \mod_assign_external::save_grade(
            $assignmentid,
            $studentid,
            $grade,
            $resolvedattempt,
            false,
            $workflowstate,
            false,
            $plugindata
        );

        $released = empty($instance->markingworkflow) || $workflowstate === ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
        $notified = false;
        if ($released) {
            $notified = quiz_grading_helper::notify_grade_released(
                $studentid,
                $actorid,
                (int)$cm->course,
                'Assignment marked',
                'Your assignment ' . format_string($instance->name) . ' has been marked.'
            );
        }
        return ['unchanged' => false, 'notified' => $notified, 'workflowstate' => $workflowstate];
    }

    /**
     * @return array{ok:bool,updated:int,message:string}
     */
    public static function bulk_mark_ungraded(
        int $assignmentid,
        float $grade,
        bool $confirm,
        int $actorid
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        if (!$confirm) {
            throw new \invalid_parameter_exception('Confirm before marking every ungraded submission.');
        }
        [$assign] = self::require_grader($assignmentid, $actorid);
        $max = (float)$assign->get_instance()->grade;
        if ($max <= 0) {
            throw new \invalid_parameter_exception('Bulk marking is only available for numeric assignment grades.');
        }
        if ($grade < 0 || $grade > $max) {
            throw new \invalid_parameter_exception('The mark must be between 0 and the assignment maximum.');
        }

        $submissions = $DB->get_records_sql(
            "SELECT s.userid, s.attemptnumber
               FROM {assign_submission} s
              WHERE s.assignment = :assignment
                AND s.latest = 1
                AND s.status = :submitted
                AND NOT EXISTS (
                    SELECT 1
                      FROM {assign_grades} g
                     WHERE g.assignment = s.assignment
                       AND g.userid = s.userid
                       AND g.attemptnumber = s.attemptnumber
                       AND g.grade >= 0
                )",
            ['assignment' => $assignmentid, 'submitted' => 'submitted']
        );
        $updated = 0;
        foreach ($submissions as $submission) {
            $result = self::save_grade(
                $assignmentid,
                (int)$submission->userid,
                $grade,
                '',
                (int)$submission->attemptnumber,
                '',
                $actorid
            );
            if (!$result['unchanged']) {
                audit_helper::log([
                    'action' => 'assignment_bulk_mark',
                    'entitytype' => 'assign_submission',
                    'entityid' => $assignmentid,
                    'before' => ['studentid' => (int)$submission->userid, 'grade' => null],
                    'after' => [
                        'studentid' => (int)$submission->userid,
                        'grade' => $grade,
                        'attemptnumber' => (int)$submission->attemptnumber,
                    ],
                    'sourcemodule' => 'assignment_grading',
                ], $actorid);
                $updated++;
            }
        }
        return [
            'ok' => true,
            'updated' => $updated,
            'message' => $updated === 0
                ? 'No ungraded submissions were waiting.'
                : 'Marked ' . $updated . ' ungraded submissions.',
        ];
    }

    /**
     * @return array{ok:bool,released:int,message:string}
     */
    public static function release_grades(int $assignmentid, int $actorid): array {
        global $DB;

        [$assign, $cm] = self::require_grader($assignmentid, $actorid);
        if (empty($assign->get_instance()->markingworkflow)) {
            return [
                'ok' => false,
                'released' => 0,
                'message' => 'This assignment does not use Moodle marking workflow.',
            ];
        }
        $flags = $DB->get_records('assign_user_flags', [
            'assignment' => $assignmentid,
            'workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE,
        ]);
        $released = 0;
        foreach ($flags as $flag) {
            $current = self::current_grade($assignmentid, (int)$flag->userid, -1);
            if (!$current || $current->grade === null || (float)$current->grade < 0) {
                continue;
            }
            self::save_grade(
                $assignmentid,
                (int)$flag->userid,
                (float)$current->grade,
                (string)($current->commenttext ?? ''),
                (int)$current->attemptnumber,
                ASSIGN_MARKING_WORKFLOW_STATE_RELEASED,
                $actorid
            );
            audit_helper::log([
                'action' => 'assignment_release',
                'entitytype' => 'assign_submission',
                'entityid' => $assignmentid,
                'before' => ['studentid' => (int)$flag->userid, 'workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE],
                'after' => ['studentid' => (int)$flag->userid, 'workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_RELEASED],
                'sourcemodule' => 'assignment_grading',
            ], $actorid);
            $released++;
        }
        return [
            'ok' => true,
            'released' => $released,
            'message' => $released === 0
                ? 'No grades were waiting for release.'
                : 'Released ' . $released . ' grades.',
        ];
    }

    /**
     * @return array{0:\assign,1:\stdClass}
     */
    private static function require_grader(int $assignmentid, int $actorid): array {
        global $DB;

        $cm = get_coursemodule_from_instance('assign', $assignmentid, 0, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        require_capability('mod/assign:grade', $context, $actorid);
        $meta = $DB->get_record('local_btiportal_assignment_meta', ['assignmentid' => $assignmentid]);
        $unitid = $meta ? (int)$meta->unitid : (int)$cm->section;
        if ($unitid <= 0) {
            throw new \moodle_exception('notunitteacher', 'local_btiportal', '', (object)[
                'courseid' => (int)$cm->course,
                'unitid' => $unitid,
            ]);
        }
        gradebook_helper::require_unit_teacher($actorid, (int)$cm->course, $unitid);
        $course = get_course((int)$cm->course);
        $assign = new \assign($context, $cm, $course);
        return [$assign, $cm];
    }

    private static function resolve_attempt(int $assignmentid, int $studentid, int $attemptnumber): int {
        global $DB;

        if ($attemptnumber >= 0) {
            return $attemptnumber;
        }
        $latest = $DB->get_field_sql(
            "SELECT MAX(attemptnumber)
               FROM {assign_submission}
              WHERE assignment = :assignment AND userid = :userid",
            ['assignment' => $assignmentid, 'userid' => $studentid]
        );
        return $latest === false || $latest === null ? 0 : (int)$latest;
    }

    private static function current_grade(int $assignmentid, int $studentid, int $attemptnumber): ?object {
        global $DB;

        $params = ['assignment' => $assignmentid, 'userid' => $studentid];
        $attemptsql = '';
        if ($attemptnumber >= 0) {
            $attemptsql = ' AND g.attemptnumber = :attemptnumber';
            $params['attemptnumber'] = $attemptnumber;
        }
        $record = $DB->get_record_sql(
            "SELECT g.id, g.grade, g.attemptnumber, c.commenttext
               FROM {assign_grades} g
          LEFT JOIN {assignfeedback_comments} c
                 ON c.grade = g.id AND c.assignment = g.assignment
              WHERE g.assignment = :assignment
                AND g.userid = :userid
                {$attemptsql}
           ORDER BY g.attemptnumber DESC, g.id DESC",
            $params,
            IGNORE_MULTIPLE
        );
        return $record ?: null;
    }

    private static function workflow_state(int $assignmentid, int $studentid): string {
        global $DB;

        $state = $DB->get_field('assign_user_flags', 'workflowstate', [
            'assignment' => $assignmentid,
            'userid' => $studentid,
        ]);
        return $state ? (string)$state : '';
    }

    private static function normalize_workflow(string $enabled, string $requested, string $current): string {
        if (empty($enabled)) {
            return '';
        }
        $allowed = [
            ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED,
            ASSIGN_MARKING_WORKFLOW_STATE_INMARKING,
            ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW,
            ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW,
            ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE,
            ASSIGN_MARKING_WORKFLOW_STATE_RELEASED,
        ];
        if ($requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }
        return $current !== '' ? $current : ASSIGN_MARKING_WORKFLOW_STATE_INMARKING;
    }
}
