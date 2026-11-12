<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Student gradebook data sourced from Moodle's own grade and quiz APIs.
 */
class student_gradebook_helper {
    /**
     * Return visible grades for one authenticated Moodle user.
     *
     * @return list<array<string, int|float|string|bool>>
     */
    public static function get_gradebook(int $userid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');

        if ($userid <= 0) {
            throw new \invalid_parameter_exception('A valid authenticated user is required.');
        }

        $results = [];
        $pendingquizzes = quiz_grading_helper::pending_quiz_ids_for_user($userid);
        $courses = enrol_get_users_courses($userid, true, 'id,fullname,shortname,showgrades,visible');
        foreach ($courses as $course) {
            $courseid = (int)$course->id;
            if ($courseid <= SITEID || empty($course->showgrades)
                    || !can_access_course($course, $userid, 'moodle/grade:view', true)) {
                continue;
            }

            $coursecontext = \context_course::instance($courseid);
            if (!has_capability('gradereport/user:view', $coursecontext)
                    || !has_capability('moodle/grade:view', $coursecontext)) {
                continue;
            }

            $course = get_course($courseid);
            $report = \gradereport_user\external\user::get_grade_items($courseid, $userid);
            $usergrade = null;
            foreach ($report['usergrades'] as $candidate) {
                if ((int)$candidate['userid'] === $userid) {
                    $usergrade = $candidate;
                    break;
                }
            }
            if (!$usergrade) {
                continue;
            }

            $unitmappings = self::get_unit_mappings($courseid);
            $assessmentmetadata = self::get_assessment_metadata($courseid);
            $assignmentmetadata = self::get_assignment_metadata($courseid);
            $modinfo = get_fast_modinfo($course, $userid);

            foreach ($usergrade['gradeitems'] as $gradeitemdata) {
                $itemmodule = (string)$gradeitemdata['itemmodule'];
                $isquiz = $gradeitemdata['itemtype'] === 'mod' && $itemmodule === 'quiz';
                $nativegradehidden = !empty($gradeitemdata['gradeishidden'])
                    || !empty($gradeitemdata['gradehiddenbydate']);
                if ($nativegradehidden && !$isquiz) {
                    continue;
                }

                $gradeitemid = (int)$gradeitemdata['id'];
                $iteminstance = (int)$gradeitemdata['iteminstance'];
                if ($gradeitemdata['itemtype'] !== 'mod') {
                    continue;
                }
                $cmid = (int)($gradeitemdata['cmid'] ?? 0);
                $cm = $cmid > 0 ? $DB->get_record('course_modules', [
                    'id' => $cmid,
                    'course' => $courseid,
                    'deletioninprogress' => 0,
                ]) : false;
                $moduleid = $DB->get_field('modules', 'id', ['name' => $itemmodule]);
                if (!$cm || !$moduleid || (int)$cm->instance !== $iteminstance
                        || (int)$cm->module !== (int)$moduleid) {
                    continue;
                }

                $cminfo = $modinfo->get_cm($cmid);
                if (!$cminfo || !$cminfo->uservisible) {
                    continue;
                }

                $assessment = self::resolve_assessment(
                    $courseid,
                    $gradeitemid,
                    $itemmodule,
                    $iteminstance,
                    $cmid,
                    (int)$cm->section,
                    $unitmappings,
                    $assessmentmetadata,
                    $assignmentmetadata
                );
                if ($assessment === null) {
                    continue;
                }

                $quizgrade = null;
                $isquizvisible = true;
                if ($isquiz) {
                    $quizgrade = \mod_quiz_external::get_user_best_grade($iteminstance, $userid);
                    $isquizvisible = !empty($quizgrade['hasgrade']);
                }

                $row = [
                    'courseid' => $courseid,
                    'coursename' => (string)$course->fullname,
                    'unitid' => $assessment['unitid'],
                    'unitname' => $assessment['unitname'],
                    'assessmentid' => $iteminstance,
                    'assessmentname' => (string)$gradeitemdata['itemname'],
                    'assessmenttype' => $assessment['type'],
                    'isfinalexam' => $assessment['isfinalexam'],
                    'status' => 'pending',
                ];
                if (!empty($assessment['assessmentdate'])) {
                    $row['assessmentdate'] = $assessment['assessmentdate'];
                }
                if ($isquiz) {
                    if (isset($quizgrade['feedback']['feedbacktext'])) {
                        $row['feedback'] = (string)$quizgrade['feedback']['feedbacktext'];
                        if (isset($quizgrade['feedback']['feedbackformat'])) {
                            $row['feedbackformat'] = (int)$quizgrade['feedback']['feedbackformat'];
                        }
                    }
                } else if (isset($gradeitemdata['feedback']) && $gradeitemdata['feedback'] !== '') {
                    $row['feedback'] = (string)$gradeitemdata['feedback'];
                    if (isset($gradeitemdata['feedbackformat'])) {
                        $row['feedbackformat'] = (int)$gradeitemdata['feedbackformat'];
                    }
                }

                $hasrange = !$nativegradehidden
                    && $isquizvisible
                    && empty($gradeitemdata['gradeneedsupdate'])
                    && isset($gradeitemdata['grademin'], $gradeitemdata['grademax'])
                    && is_numeric($gradeitemdata['grademin'])
                    && is_numeric($gradeitemdata['grademax'])
                    && (float)$gradeitemdata['grademax'] > (float)$gradeitemdata['grademin'];
                if ($hasrange) {
                    $row['maximum'] = (float)$gradeitemdata['grademax'];
                }

                $hasgrade = !$nativegradehidden
                    && $isquizvisible
                    && empty($gradeitemdata['gradeneedsupdate'])
                    && isset($gradeitemdata['graderaw'])
                    && is_numeric($gradeitemdata['graderaw']);
                if ($hasgrade) {
                    $score = (float)$gradeitemdata['graderaw'];
                    $row['score'] = $score;
                    if ($hasrange) {
                        $row['percentage'] = ($score - (float)$gradeitemdata['grademin'])
                            / ((float)$gradeitemdata['grademax'] - (float)$gradeitemdata['grademin']) * 100;
                    }
                    $row['status'] = 'graded';
                    if ($isquiz && isset($pendingquizzes[$iteminstance]) && $isquizvisible) {
                        $row['status'] = 'provisional';
                    }
                    if (!empty($gradeitemdata['gradedategraded'])) {
                        $row['gradedate'] = (int)$gradeitemdata['gradedategraded'];
                    } else if (!empty($gradeitemdata['gradedatesubmitted'])) {
                        $row['gradedate'] = (int)$gradeitemdata['gradedatesubmitted'];
                    }
                }

                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * @return array<int, array<int, int>> grade item id => matching unit ids
     */
    private static function get_unit_mappings(int $courseid): array {
        global $DB;

        $mappings = [];
        foreach ($DB->get_records('local_btiportal_unit_gradeitem', ['courseid' => $courseid]) as $mapping) {
            $mappings[(int)$mapping->gradeitemid][] = (int)$mapping->unitid;
        }
        return $mappings;
    }

    /**
     * @return array<int, stdClass> course-module id => assessment metadata
     */
    private static function get_assessment_metadata(int $courseid): array {
        global $DB;

        $metadata = [];
        foreach ($DB->get_records('local_btiportal_assessment_meta', ['courseid' => $courseid]) as $record) {
            $metadata[(int)$record->cmid] = $record;
        }
        return $metadata;
    }

    /**
     * @return array<int, stdClass> assignment instance id => assignment metadata
     */
    private static function get_assignment_metadata(int $courseid): array {
        global $DB;

        $metadata = [];
        foreach ($DB->get_records('local_btiportal_assignment_meta', ['courseid' => $courseid]) as $record) {
            $metadata[(int)$record->assignmentid] = $record;
        }
        return $metadata;
    }

    /**
     * Resolve only activities explicitly represented by BTI assessment metadata.
     *
     * @param array<int, array<int, int>> $unitmappings
     * @param array<int, stdClass> $assessmentmetadata
     * @param array<int, stdClass> $assignmentmetadata
     * @return array{unitid:int, unitname:string, type:string, isfinalexam:bool, assessmentdate?:int}|null
     */
    private static function resolve_assessment(
        int $courseid,
        int $gradeitemid,
        string $itemmodule,
        int $iteminstance,
        int $cmid,
        int $sectionid,
        array $unitmappings,
        array $assessmentmetadata,
        array $assignmentmetadata
    ): ?array {
        global $DB;

        if ($itemmodule === 'quiz') {
            $metadata = $assessmentmetadata[$cmid] ?? null;
            $quiz = $DB->get_record('quiz', ['id' => $iteminstance, 'course' => $courseid]);
            $storedtype = strtolower(trim((string)($metadata->assessmenttype ?? '')));
            if ($storedtype === 'exam') {
                $storedtype = assessment_helper::TYPE_FINAL;
            }
            if (!$quiz || !$metadata || (int)$metadata->courseid !== $courseid
                    || (int)$metadata->instanceid !== $iteminstance
                    || $metadata->module !== 'quiz'
                    || !in_array($storedtype, assessment_helper::allowed_types(), true)) {
                return null;
            }

            $type = assessment_helper::normalize_type($storedtype);
            if ($type === assessment_helper::TYPE_FINAL) {
                if ((int)$metadata->unitid !== 0) {
                    return null;
                }
                return [
                    'unitid' => 0,
                    'unitname' => '',
                    'type' => 'final_exam',
                    'isfinalexam' => true,
                    'assessmentdate' => (int)$quiz->timeclose,
                ];
            }

            $mappedunits = $unitmappings[$gradeitemid] ?? [];
            if ((int)$metadata->unitid <= 0 || (int)$metadata->unitid !== $sectionid
                    || count($mappedunits) !== 1 || $mappedunits[0] !== (int)$metadata->unitid) {
                return null;
            }

            $type = match ($type) {
                assessment_helper::TYPE_CAT => 'cat',
                assessment_helper::TYPE_MIDTERM => 'exam',
                assessment_helper::TYPE_ASSIGNMENT, assessment_helper::TYPE_PRACTICAL => 'assignment',
                default => 'quiz',
            };
            $assessmentdate = (int)$quiz->timeclose;
        } else if ($itemmodule === 'assign') {
            $metadata = $assignmentmetadata[$iteminstance] ?? null;
            $mappedunits = $unitmappings[$gradeitemid] ?? [];
            $assignment = $DB->get_record('assign', ['id' => $iteminstance, 'course' => $courseid]);
            $category = strtoupper(trim((string)($metadata->category ?? '')));
            if ($category === 'ASSIGNMENTS') {
                $category = assignment_meta_helper::CATEGORY_ASSIGNMENT;
            }
            if (!$assignment || !$metadata || (int)$metadata->courseid !== $courseid
                    || (int)$metadata->unitid !== $sectionid
                    || count($mappedunits) !== 1 || $mappedunits[0] !== $sectionid
                    || !in_array($category, assignment_meta_helper::allowed_categories(), true)) {
                return null;
            }

            $type = match ($category) {
                assignment_meta_helper::CATEGORY_CAT => 'cat',
                assignment_meta_helper::CATEGORY_EXAM => 'exam',
                default => 'assignment',
            };
            $assessmentdate = (int)$assignment->duedate;
        } else {
            return null;
        }

        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid]);
        if (!$section) {
            return null;
        }

        $course = get_course($courseid);
        $unitname = trim((string)$section->name);
        if ($unitname === '') {
            $unitname = course_get_format($course)->get_section_name($section);
        }

        return [
            'unitid' => $sectionid,
            'unitname' => $unitname,
            'type' => $type,
            'isfinalexam' => false,
            'assessmentdate' => $assessmentdate,
        ];
    }
}
