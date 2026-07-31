<?php
/**
 * Stage D/F isolation fixture:
 * - Ensure portal_instructor2 exists
 * - Ensure two topic sections with one assign each on course 2
 * - Map section A → portal_instructor, section B → portal_instructor2
 *
 * Run: docker exec moodle-app php /var/www/html/public/local/btiportal/cli_setup_two_unit_teachers.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/local/btiportal/classes/unit_helper.php');

$courseid = 2;
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
$teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
$coursecontext = context_course::instance($courseid);

function ensure_portal_teacher(string $username, string $password, string $firstname, string $lastname, string $email, int $courseid, $teacherrole, $service): stdClass {
    global $DB, $CFG;
    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $user = (object)[
            'username' => $username,
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
        ];
        $user->id = user_create_user($user, false, false);
        echo "Created {$username} id={$user->id}\n";
    } else {
        echo "User {$username} exists id={$user->id}\n";
    }
    update_internal_user_password($user, $password);
    echo "  password set\n";

    $enrol = enrol_get_plugin('manual');
    $instances = enrol_get_instances($courseid, true);
    $manual = null;
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $manual = $instance;
            break;
        }
    }
    if ($manual) {
        if (!$DB->record_exists('user_enrolments', ['userid' => $user->id, 'enrolid' => $manual->id])) {
            $enrol->enrol_user($manual, $user->id, $teacherrole->id);
            echo "  enrolled as editingteacher on course {$courseid}\n";
        } else {
            role_assign($teacherrole->id, $user->id, context_course::instance($courseid)->id);
            echo "  ensured editingteacher on course {$courseid}\n";
        }
    }

    if ((int)$service->restrictedusers === 1) {
        if (!$DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ])) {
            $DB->insert_record('external_services_users', (object)[
                'externalserviceid' => $service->id,
                'userid' => $user->id,
                'timecreated' => time(),
            ]);
            echo "  authorised for bti_portal_api\n";
        }
    }
    return $user;
}

function ensure_topic_section(int $courseid, int $sectionnum, string $name): stdClass {
    global $DB;
    course_create_sections_if_missing($courseid, $sectionnum);
    $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum], '*', MUST_EXIST);
    if ($section->name !== $name) {
        $DB->set_field('course_sections', 'name', $name, ['id' => $section->id]);
        $section->name = $name;
        rebuild_course_cache($courseid, true);
    }
    echo "Section {$sectionnum} id={$section->id} name={$section->name}\n";
    return $section;
}

function ensure_assign_in_section(stdClass $course, stdClass $section, string $assignname): stdClass {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/modlib.php');

    $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);
    $sql = "SELECT a.*
              FROM {assign} a
              JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = :moduleid
             WHERE a.course = :courseid AND cm.section = :sectionid AND a.name = :name";
    $existing = $DB->get_record_sql($sql, [
        'moduleid' => $module->id,
        'courseid' => $course->id,
        'sectionid' => $section->id,
        'name' => $assignname,
    ]);
    if ($existing) {
        $cm = $DB->get_record('course_modules', [
            'module' => $module->id,
            'instance' => $existing->id,
        ], '*', MUST_EXIST);
        echo "  assign exists id={$existing->id} cmid={$cm->id} name={$assignname}\n";
        return (object)['assign' => $existing, 'cm' => $cm];
    }

    // Minimal direct insert — avoid add_moduleinfo transaction issues in CLI.
    $now = time();
    $assignid = $DB->insert_record('assign', (object)[
        'course' => $course->id,
        'name' => $assignname,
        'intro' => '<p>Portal isolation test assignment</p>',
        'introformat' => FORMAT_HTML,
        'alwaysshowdescription' => 1,
        'nosubmissions' => 0,
        'submissiondrafts' => 0,
        'sendnotifications' => 0,
        'sendlatenotifications' => 0,
        'duedate' => $now + 14 * DAYSECS,
        'allowsubmissionsfromdate' => $now - DAYSECS,
        'grade' => 100,
        'timemodified' => $now,
        'requiresubmissionstatement' => 0,
        'teamsubmission' => 0,
        'requireallteammemberssubmit' => 0,
        'teamsubmissiongroupingid' => 0,
        'blindmarking' => 0,
        'hidegrader' => 0,
        'revealidentities' => 0,
        'attemptreopenmethod' => 'none',
        'maxattempts' => -1,
        'markingworkflow' => 0,
        'markingallocation' => 0,
        'sendstudentnotifications' => 0,
        'preventsubmissionnotingroup' => 0,
        'activity' => '',
        'activityformat' => FORMAT_HTML,
        'timelimit' => 0,
        'submissionattachments' => 0,
        'cutoffdate' => 0,
        'gradingduedate' => 0,
    ]);

    $cmid = $DB->insert_record('course_modules', (object)[
        'course' => $course->id,
        'module' => $module->id,
        'instance' => $assignid,
        'section' => $section->id,
        'idnumber' => '',
        'added' => $now,
        'score' => 0,
        'indent' => 0,
        'visible' => 1,
        'visibleoncoursepage' => 1,
        'visibleold' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'completion' => 0,
        'completiongradeitemnumber' => null,
        'completionview' => 0,
        'completionexpected' => 0,
        'showdescription' => 0,
        'availability' => null,
        'deletioninprogress' => 0,
        'downloadcontent' => 1,
    ]);

    // Append to section sequence.
    $seq = trim($section->sequence ?? '');
    $section->sequence = $seq === '' ? (string)$cmid : $seq . ',' . $cmid;
    $DB->update_record('course_sections', $section);

    // Enable online text submission + comments feedback.
    $DB->insert_record('assign_plugin_config', (object)[
        'assignment' => $assignid,
        'plugin' => 'onlinetext',
        'subtype' => 'assignsubmission',
        'name' => 'enabled',
        'value' => '1',
    ]);
    $DB->insert_record('assign_plugin_config', (object)[
        'assignment' => $assignid,
        'plugin' => 'comments',
        'subtype' => 'assignfeedback',
        'name' => 'enabled',
        'value' => '1',
    ]);

    // Grade item.
    require_once($CFG->libdir . '/gradelib.php');
    grade_update('mod/assign', $course->id, 'mod', 'assign', $assignid, 0, null, [
        'itemname' => $assignname,
        'grademax' => 100,
        'grademin' => 0,
        'gradetype' => GRADE_TYPE_VALUE,
    ]);

    rebuild_course_cache($course->id, true);

    $assignrec = $DB->get_record('assign', ['id' => $assignid], '*', MUST_EXIST);
    $cmrecord = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
    echo "  created assign id={$assignrec->id} cmid={$cmrecord->id} name={$assignname}\n";
    return (object)['assign' => $assignrec, 'cm' => $cmrecord];
}

echo "=== Course {$courseid} {$course->shortname} ===\n";

$t1 = ensure_portal_teacher(
    'portal_instructor',
    'PortalInstructor2026!',
    'Portal',
    'Instructor',
    'portal.instructor@bti.test',
    $courseid,
    $teacherrole,
    $service
);
$t2 = ensure_portal_teacher(
    'portal_instructor2',
    'PortalInstructor2_2026!',
    'Portal',
    'InstructorTwo',
    'portal.instructor2@bti.test',
    $courseid,
    $teacherrole,
    $service
);

$sec1 = ensure_topic_section($courseid, 1, 'Unit A — Databases Intro');
$sec2 = ensure_topic_section($courseid, 2, 'Unit B — Query Optimisation');

$a1 = ensure_assign_in_section($course, $sec1, 'Unit A Assignment — Isolation Test');
$a2 = ensure_assign_in_section($course, $sec2, 'Unit B Assignment — Isolation Test');

$m1 = \local_btiportal\unit_helper::ensure_unit_teacher($courseid, (int)$sec1->id, (int)$t1->id);
$m2 = \local_btiportal\unit_helper::ensure_unit_teacher($courseid, (int)$sec2->id, (int)$t2->id);

echo "Mapped section {$sec1->id} → teacher {$t1->id} ({$t1->username}) created=" . ($m1['created'] ? 'yes' : 'no') . "\n";
echo "Mapped section {$sec2->id} → teacher {$t2->id} ({$t2->username}) created=" . ($m2['created'] ? 'yes' : 'no') . "\n";

$maps = $DB->get_records('local_btiportal_unit', ['courseid' => $courseid]);
foreach ($maps as $m) {
    $u = $DB->get_record('user', ['id' => $m->teacheruserid], 'username');
    echo "DB map course={$m->courseid} section={$m->sectionid} teacher={$u->username}\n";
}

echo "\nCredentials:\n";
echo "  portal_instructor / PortalInstructor2026!  → Unit A (section {$sec1->id})\n";
echo "  portal_instructor2 / PortalInstructor2_2026! → Unit B (section {$sec2->id})\n";
echo "  Assignments: A={$a1->assign->id} B={$a2->assign->id}\n";
echo "DONE\n";
