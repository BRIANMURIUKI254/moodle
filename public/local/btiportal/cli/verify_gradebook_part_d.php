<?php
/**
 * Part D fixture + printed verification for unit-scoped gradebook.
 *
 * Creates/ensures:
 * - Two teachers on distinct units of the same course
 * - Unit A: assignment + manual + quiz grade items (mapped)
 * - Unit B: a separate assignment (mapped) for Teacher 2
 * - One student enrolled
 *
 * Then prints request/response evidence for auth rejection, writes, quiz block,
 * and unmapped gradeitem rejection.
 *
 * Run:
 *   docker exec moodle-app php /var/www/html/public/local/btiportal/cli/verify_gradebook_part_d.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/webservice/lib.php');
require_once($CFG->dirroot . '/lib/externallib.php');

use local_btiportal\gradebook_helper;
use local_btiportal\unit_helper;

function gb_println(string $s = ''): void {
    echo $s . PHP_EOL;
}

function gb_ensure_user(string $username, string $password, string $firstname, string $lastname, string $email): stdClass {
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
        gb_println("Created user {$username} id={$user->id}");
    } else {
        gb_println("User {$username} exists id={$user->id}");
    }
    update_internal_user_password($user, $password);
    return $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
}

function gb_enrol_as(stdClass $user, int $courseid, string $roleshortname): void {
    global $DB;
    $role = $DB->get_record('role', ['shortname' => $roleshortname], '*', MUST_EXIST);
    $enrol = enrol_get_plugin('manual');
    $instances = enrol_get_instances($courseid, true);
    $manual = null;
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $manual = $instance;
            break;
        }
    }
    if (!$manual) {
        throw new moodle_exception('nomanualenrol', 'enrol_manual');
    }
    if (!$DB->record_exists('user_enrolments', ['userid' => $user->id, 'enrolid' => $manual->id])) {
        $enrol->enrol_user($manual, $user->id, $role->id);
    } else {
        role_assign($role->id, $user->id, context_course::instance($courseid)->id);
    }
}

function gb_authorize_ws(stdClass $user): void {
    global $DB;
    $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
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
        }
    }
}

function gb_get_token(stdClass $user): string {
    global $DB;
    $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
    $existing = $DB->get_record('external_tokens', [
        'userid' => $user->id,
        'externalserviceid' => $service->id,
        'tokentype' => EXTERNAL_TOKEN_PERMANENT,
    ]);
    if ($existing && !empty($existing->token)) {
        return $existing->token;
    }
    return external_generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $user->id, context_system::instance());
}

function gb_ws_call_as(stdClass $user, string $function, array $params): array {
    global $USER;
    $previous = $USER;
    \core\session\manager::set_user($user);

    $map = [
        'local_btiportal_get_unit_gradebook' => [\local_btiportal\external\get_unit_gradebook::class, 'execute'],
        'local_btiportal_submit_unit_grade' => [\local_btiportal\external\submit_unit_grade::class, 'execute'],
    ];

    $payload = [
        '_request' => [
            'userid' => (int)$user->id,
            'username' => $user->username,
            'wsfunction' => $function,
            'params' => $params,
        ],
    ];

    try {
        if (!isset($map[$function])) {
            throw new moodle_exception('invalidfunction', 'webservice', '', $function);
        }
        [$class, $method] = $map[$function];
        $args = array_values($params);
        $data = $class::$method(...$args);
        // Validate return shape the same way REST would.
        $data = \core_external\external_api::clean_returnvalue($class::execute_returns(), $data);
        $payload['_response'] = $data;
    } catch (Throwable $e) {
        $payload['_response'] = [
            'exception' => get_class($e),
            'errorcode' => ($e instanceof moodle_exception) ? $e->errorcode : 'error',
            'message' => $e->getMessage(),
            'debuginfo' => ($e instanceof moodle_exception) ? ($e->debuginfo ?? null) : null,
        ];
    } finally {
        \core\session\manager::set_user($previous);
    }

    $payload['_raw'] = json_encode($payload['_response'], JSON_PRETTY_PRINT);
    return $payload;
}

function gb_print_pair(string $title, array $pack): void {
    gb_println('');
    gb_println('======== ' . $title . ' ========');
    gb_println('REQUEST: ' . json_encode($pack['_request'], JSON_PRETTY_PRINT));
    if (isset($pack['_curl_error'])) {
        gb_println('CURL ERROR: ' . $pack['_curl_error']);
        return;
    }
    gb_println('RESPONSE: ' . (is_string($pack['_raw'] ?? null) ? $pack['_raw'] : json_encode($pack['_response'], JSON_PRETTY_PRINT)));
}

function gb_add_assign(stdClass $course, int $sectionnum, string $name): stdClass {
    global $DB;
    $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);
    $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $sectionnum], '*', MUST_EXIST);

    $existingcm = $DB->get_record_sql(
        "SELECT cm.* FROM {course_modules} cm
           JOIN {assign} a ON a.id = cm.instance
          WHERE cm.course = ? AND cm.module = ? AND cm.section = ? AND a.name = ?",
        [$course->id, $module->id, $section->id, $name]
    );
    if ($existingcm) {
        return $existingcm;
    }

    $fromform = (object)[
        'name' => $name,
        'course' => $course->id,
        'section' => $sectionnum,
        'module' => $module->id,
        'modulename' => 'assign',
        'instance' => 0,
        'intro' => $name,
        'introformat' => FORMAT_HTML,
        'alwaysshowdescription' => 1,
        'submissiondrafts' => 0,
        'requiresubmissionstatement' => 0,
        'sendnotifications' => 0,
        'sendlatenotifications' => 0,
        'sendstudentnotifications' => 0,
        'duedate' => 0,
        'allowsubmissionsfromdate' => 0,
        'grade' => 100,
        'cutoffdate' => 0,
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
        'assignsubmission_onlinetext_enabled' => 1,
        'assignsubmission_file_enabled' => 0,
        'visible' => 1,
        'cmidnumber' => '',
        'groupmode' => 0,
        'groupingid' => 0,
        'completion' => 0,
        'completionexpected' => 0,
        'showdescription' => 0,
        'availabilityconditionsjson' => '',
    ];
    $cm = add_moduleinfo($fromform, $course);
    return $DB->get_record('course_modules', ['id' => $cm->coursemodule], '*', MUST_EXIST);
}

function gb_add_quiz(stdClass $course, int $sectionnum, string $name): stdClass {
    global $DB;
    $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
    $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $sectionnum], '*', MUST_EXIST);

    $existingcm = $DB->get_record_sql(
        "SELECT cm.* FROM {course_modules} cm
           JOIN {quiz} q ON q.id = cm.instance
          WHERE cm.course = ? AND cm.module = ? AND cm.section = ? AND q.name = ?",
        [$course->id, $module->id, $section->id, $name]
    );
    if ($existingcm) {
        return $existingcm;
    }

    $fromform = (object)[
        'name' => $name,
        'course' => $course->id,
        'section' => $sectionnum,
        'module' => $module->id,
        'modulename' => 'quiz',
        'instance' => 0,
        'intro' => $name,
        'introformat' => FORMAT_HTML,
        'timeopen' => 0,
        'timeclose' => 0,
        'timelimit' => 0,
        'overduehandling' => 'autosubmit',
        'graceperiod' => 0,
        'preferredbehaviour' => 'deferredfeedback',
        'canredoquestions' => 0,
        'attempts' => 0,
        'attemptonlast' => 0,
        'grademethod' => 1,
        'decimalpoints' => 2,
        'questiondecimalpoints' => -1,
        'reviewattempt' => 0,
        'reviewcorrectness' => 0,
        'reviewmarks' => 0,
        'reviewspecificfeedback' => 0,
        'reviewgeneralfeedback' => 0,
        'reviewrightanswer' => 0,
        'reviewoverallfeedback' => 0,
        'questionsperpage' => 1,
        'navmethod' => 'free',
        'shuffleanswers' => 1,
        'sumgrades' => 0,
        'grade' => 100,
        'visible' => 1,
        'cmidnumber' => '',
        'groupmode' => 0,
        'groupingid' => 0,
        'completion' => 0,
        'completionexpected' => 0,
        'showdescription' => 0,
        'availabilityconditionsjson' => '',
        'quizpassword' => '',
        'subnet' => '',
        'delay1' => 0,
        'delay2' => 0,
        'browsersecurity' => '-',
        'boundary_repeats' => 0,
    ];
    $cm = add_moduleinfo($fromform, $course);
    return $DB->get_record('course_modules', ['id' => $cm->coursemodule], '*', MUST_EXIST);
}

function gb_ensure_manual_item(int $courseid, string $name): int {
    global $DB;
    $existing = $DB->get_record('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'manual',
        'itemname' => $name,
    ]);
    if ($existing) {
        return (int)$existing->id;
    }
    $gi = new grade_item([
        'courseid' => $courseid,
        'categoryid' => grade_category::fetch_course_category($courseid)->id,
        'itemtype' => 'manual',
        'itemname' => $name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => 100,
        'grademin' => 0,
    ]);
    $gi->insert();
    return (int)$gi->id;
}

function gb_gradeitem_for_cm(int $courseid, stdClass $cm, string $modname): int {
    global $DB;
    $gi = $DB->get_record('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'mod',
        'itemmodule' => $modname,
        'iteminstance' => $cm->instance,
        'itemnumber' => 0,
    ], '*', MUST_EXIST);
    return (int)$gi->id;
}

// ---------------------------------------------------------------------------
// Upgrade / setup
// ---------------------------------------------------------------------------
gb_println('=== Part D: unit gradebook verification ===');

$course = $DB->get_record('course', ['shortname' => 'TEST01'], '*', IGNORE_MISSING);
if (!$course) {
    $course = $DB->get_record_sql("SELECT * FROM {course} WHERE id > 1 ORDER BY id ASC", [], IGNORE_MULTIPLE);
}
if (!$course) {
    cli_error('No usable course found');
}
$courseid = (int)$course->id;
gb_println("Using course id={$courseid} shortname={$course->shortname}");

$teacher1 = gb_ensure_user('portal_instructor', 'PortalTeach1!', 'Portal', 'Instructor', 'portal_instructor@example.com');
$teacher2 = gb_ensure_user('portal_instructor2', 'PortalTeach2!', 'Portal', 'Instructor2', 'portal_instructor2@example.com');
$student = gb_ensure_user('portal_student', 'PortalStud1!', 'Portal', 'Student', 'portal_student@example.com');

gb_enrol_as($teacher1, $courseid, 'editingteacher');
gb_enrol_as($teacher2, $courseid, 'editingteacher');
gb_enrol_as($student, $courseid, 'student');
gb_authorize_ws($teacher1);
gb_authorize_ws($teacher2);

// Ensure two topic sections.
$modinfo = get_fast_modinfo($course);
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
$topics = array_values(array_filter($sections, fn($s) => (int)$s->section > 0));
while (count($topics) < 2) {
    $sectionnum = count($topics) + 1;
    course_create_sections_if_missing($courseid, $sectionnum);
    $topics = array_values(array_filter(
        $DB->get_records('course_sections', ['course' => $courseid], 'section ASC'),
        fn($s) => (int)$s->section > 0
    ));
}
$unitA = $topics[0];
$unitB = $topics[1];
if (empty($unitA->name)) {
    $DB->set_field('course_sections', 'name', 'Gradebook Unit A', ['id' => $unitA->id]);
}
if (empty($unitB->name)) {
    $DB->set_field('course_sections', 'name', 'Gradebook Unit B', ['id' => $unitB->id]);
}

unit_helper::ensure_unit_teacher($courseid, (int)$unitA->id, (int)$teacher1->id);
unit_helper::ensure_unit_teacher($courseid, (int)$unitB->id, (int)$teacher2->id);
gb_println("Mapped Teacher1={$teacher1->id} -> unitA={$unitA->id}; Teacher2={$teacher2->id} -> unitB={$unitB->id}");

$assignAcm = gb_add_assign($course, (int)$unitA->section, 'GB Unit A Assignment');
$quizAcm = gb_add_quiz($course, (int)$unitA->section, 'GB Unit A Quiz');
$assignBcm = gb_add_assign($course, (int)$unitB->section, 'GB Unit B Assignment');
$manualAid = gb_ensure_manual_item($courseid, 'GB Unit A Manual');

$assignAgi = gb_gradeitem_for_cm($courseid, $assignAcm, 'assign');
$quizAgi = gb_gradeitem_for_cm($courseid, $quizAcm, 'quiz');
$assignBgi = gb_gradeitem_for_cm($courseid, $assignBcm, 'assign');

gradebook_helper::map_grade_item($courseid, (int)$unitA->id, $assignAgi);
gradebook_helper::map_grade_item($courseid, (int)$unitA->id, $manualAid);
gradebook_helper::map_grade_item($courseid, (int)$unitA->id, $quizAgi);
gradebook_helper::map_grade_item($courseid, (int)$unitB->id, $assignBgi);

$token1 = gb_get_token($teacher1);
$token2 = gb_get_token($teacher2);
gb_println("Teacher1 token issued (len=" . strlen($token1) . "); Teacher2 token issued (len=" . strlen($token2) . ")");

// D2: Teacher 1 gradebook
$packGet = gb_ws_call_as($teacher1, 'local_btiportal_get_unit_gradebook', [
    'courseid' => $courseid,
    'unitid' => (int)$unitA->id,
]);
gb_print_pair('D2 Teacher1 get_unit_gradebook (own unit) — grade items with types', $packGet);
if (is_array($packGet['_response']['gradeitems'] ?? null)) {
    gb_println('GRADE ITEM LIST:');
    foreach ($packGet['_response']['gradeitems'] as $gi) {
        gb_println('  - id=' . $gi['id']
            . ' name=' . ($gi['name'] ?? '')
            . ' itemmodule=' . ($gi['itemmodule'] ?? '')
            . ' itemtype=' . ($gi['itemtype'] ?? '')
            . ' readonly=' . json_encode($gi['readonly'] ?? null));
    }
}

// D3: Teacher 1 tries Teacher 2's unit
$packAuth = gb_ws_call_as($teacher1, 'local_btiportal_get_unit_gradebook', [
    'courseid' => $courseid,
    'unitid' => (int)$unitB->id,
]);
gb_print_pair('D3 Cross-teacher get_unit_gradebook REJECT (Teacher1 -> Teacher2 unit)', $packAuth);

$packAuthSubmit = gb_ws_call_as($teacher1, 'local_btiportal_submit_unit_grade', [
    'courseid' => $courseid,
    'unitid' => (int)$unitB->id,
    'studentid' => (int)$student->id,
    'gradeitemid' => $assignBgi,
    'grade' => 55,
]);
gb_print_pair('D3 Cross-teacher submit_unit_grade REJECT', $packAuthSubmit);

// D4: assign write
$packAssign = gb_ws_call_as($teacher1, 'local_btiportal_submit_unit_grade', [
    'courseid' => $courseid,
    'unitid' => (int)$unitA->id,
    'studentid' => (int)$student->id,
    'gradeitemid' => $assignAgi,
    'grade' => 87,
]);
gb_print_pair('D4 Submit assignment grade (expect writesource=mod_assign_save_grade)', $packAssign);

// D5: manual write
$packManual = gb_ws_call_as($teacher1, 'local_btiportal_submit_unit_grade', [
    'courseid' => $courseid,
    'unitid' => (int)$unitA->id,
    'studentid' => (int)$student->id,
    'gradeitemid' => $manualAid,
    'grade' => 91,
]);
gb_print_pair('D5 Submit manual grade (expect writesource=core_grades_update_grades)', $packManual);

// D6: quiz block
$packQuiz = gb_ws_call_as($teacher1, 'local_btiportal_submit_unit_grade', [
    'courseid' => $courseid,
    'unitid' => (int)$unitA->id,
    'studentid' => (int)$student->id,
    'gradeitemid' => $quizAgi,
    'grade' => 40,
]);
gb_print_pair('D6 Quiz submit blocked (expect not editable / readonly_quiz)', $packQuiz);

// D7: unmapped grade item (Teacher2's assign while on Unit A)
$packUnmapped = gb_ws_call_as($teacher1, 'local_btiportal_submit_unit_grade', [
    'courseid' => $courseid,
    'unitid' => (int)$unitA->id,
    'studentid' => (int)$student->id,
    'gradeitemid' => $assignBgi,
    'grade' => 33,
]);
gb_print_pair('D7 Unmapped gradeitemid REJECT (Unit B assign on Unit A)', $packUnmapped);

gb_println('');
gb_println('=== Fixture ids ===');
gb_println(json_encode([
    'courseid' => $courseid,
    'unitA' => (int)$unitA->id,
    'unitB' => (int)$unitB->id,
    'teacher1' => (int)$teacher1->id,
    'teacher2' => (int)$teacher2->id,
    'student' => (int)$student->id,
    'assignA_gradeitem' => $assignAgi,
    'manualA_gradeitem' => $manualAid,
    'quizA_gradeitem' => $quizAgi,
    'assignB_gradeitem' => $assignBgi,
], JSON_PRETTY_PRINT));

gb_println('Done.');
