<?php
/**
 * Isolation proof: student submits to Unit A + Unit B assigns; print each teacher's visible submissions.
 * Run: docker exec moodle-app php /var/www/html/public/local/btiportal/cli_prove_isolation.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->libdir . '/externallib.php');

$student = $DB->get_record('user', ['username' => 'portal_student'], '*', MUST_EXIST);
$t1 = $DB->get_record('user', ['username' => 'portal_instructor'], '*', MUST_EXIST);
$t2 = $DB->get_record('user', ['username' => 'portal_instructor2'], '*', MUST_EXIST);

$a1 = $DB->get_record('assign', ['id' => 1], '*', MUST_EXIST);
$a2 = $DB->get_record('assign', ['id' => 2], '*', MUST_EXIST);

function submit_as(stdClass $user, stdClass $assign, string $text): void {
    global $USER, $DB, $CFG;
    $USER = $user;
    \core\session\manager::set_user($user);

    $cm = get_coursemodule_from_instance('assign', $assign->id, $assign->course, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    $assignment = new assign($context, $cm, get_course($assign->course));

    $data = (object)[
        'onlinetext_editor' => [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ],
    ];
    $notices = [];
    $assignment->save_submission($data, $notices);
    if ($notices) {
        echo "WARN notices for assign {$assign->id}: " . implode('; ', $notices) . "\n";
    }
    $assignment->submit_for_grading($data, $notices);
    echo "Submitted as {$user->username} to assign={$assign->id} ({$assign->name})\n";
}

submit_as($student, $a1, '<p>Student submission for UNIT A only — visible to portal_instructor</p>');
submit_as($student, $a2, '<p>Student submission for UNIT B only — visible to portal_instructor2</p>');

function submissions_for_teacher(stdClass $teacher): array {
    global $DB, $USER;
    $USER = $teacher;
    \core\session\manager::set_user($teacher);

    $sections = $DB->get_records('local_btiportal_unit', ['teacheruserid' => $teacher->id]);
    $sectionids = array_map(fn($s) => (int)$s->sectionid, $sections);
    $out = [];
    foreach ($sections as $map) {
        // Assigns whose CM is in this section.
        $assigns = $DB->get_records_sql(
            "SELECT a.id, a.name, cm.id AS cmid
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              WHERE a.course = :courseid AND cm.section = :sectionid",
            ['courseid' => $map->courseid, 'sectionid' => $map->sectionid]
        );
        foreach ($assigns as $a) {
            $subs = $DB->get_records('assign_submission', [
                'assignment' => $a->id,
                'status' => 'submitted',
            ]);
            foreach ($subs as $sub) {
                $su = $DB->get_record('user', ['id' => $sub->userid], 'username');
                $out[] = [
                    'teacher' => $teacher->username,
                    'sectionid' => (int)$map->sectionid,
                    'assign' => $a->name,
                    'assignid' => (int)$a->id,
                    'student' => $su->username,
                    'status' => $sub->status,
                ];
            }
        }
    }
    return $out;
}

echo "\n=== Teacher queues (plugin-scoped) ===\n";
foreach ([$t1, $t2] as $t) {
    $rows = submissions_for_teacher($t);
    echo "{$t->username} sees " . count($rows) . " submission(s):\n";
    foreach ($rows as $r) {
        echo "  section={$r['sectionid']} assign={$r['assign']} (id={$r['assignid']}) student={$r['student']}\n";
    }
}

// Cross-check: neither should see the other's assign.
$t1rows = submissions_for_teacher($t1);
$t2rows = submissions_for_teacher($t2);
$t1assignids = array_unique(array_column($t1rows, 'assignid'));
$t2assignids = array_unique(array_column($t2rows, 'assignid'));
$overlap = array_intersect($t1assignids, $t2assignids);
echo "\nOverlap assign ids between teachers: " . (empty($overlap) ? 'NONE (PASS)' : implode(',', $overlap) . ' (FAIL)') . "\n";
$t1hasA = in_array(1, $t1assignids, true);
$t1hasB = in_array(2, $t1assignids, true);
$t2hasA = in_array(1, $t2assignids, true);
$t2hasB = in_array(2, $t2assignids, true);
echo "portal_instructor has A=" . ($t1hasA ? 'yes' : 'no') . " B=" . ($t1hasB ? 'yes' : 'no') . " — expect A=yes B=no\n";
echo "portal_instructor2 has A=" . ($t2hasA ? 'yes' : 'no') . " B=" . ($t2hasB ? 'yes' : 'no') . " — expect A=no B=yes\n";
$pass = $t1hasA && !$t1hasB && !$t2hasA && $t2hasB && empty($overlap);
echo $pass ? "ISOLATION_PASS\n" : "ISOLATION_FAIL\n";
