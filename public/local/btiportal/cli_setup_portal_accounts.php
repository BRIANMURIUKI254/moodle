<?php
/**
 * One-time setup: portal test accounts + register get_current_user_context on BTI Portal API.
 * Run: docker exec moodle-app php /var/www/html/public/local/btiportal/cli_setup_portal_accounts.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');

$accounts = [
    'portal_admin' => [
        'password' => 'PortalAdmin2026!',
        'firstname' => 'Portal',
        'lastname' => 'Admin',
        'email' => 'portal.admin@bti.test',
        'systemrole' => 'manager',
    ],
    'portal_instructor' => [
        'password' => 'PortalInstructor2026!',
        'firstname' => 'Portal',
        'lastname' => 'Instructor',
        'email' => 'portal.instructor@bti.test',
        'courserole' => 'editingteacher',
    ],
    'portal_instructor2' => [
        'password' => 'PortalInstructor2_2026!',
        'firstname' => 'Portal',
        'lastname' => 'InstructorTwo',
        'email' => 'portal.instructor2@bti.test',
        'courserole' => 'editingteacher',
    ],
    'portal_student' => [
        'password' => 'PortalStudent2026!',
        'firstname' => 'Portal',
        'lastname' => 'Student',
        'email' => 'portal.student@bti.test',
        'courserole' => 'student',
    ],
];

$courseid = 2; // CSC410 — use first real teaching course
$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
if (!$service) {
    $service = $DB->get_record('external_services', ['id' => 2], '*', MUST_EXIST);
}
echo "BTI Portal API service id={$service->id} shortname={$service->shortname} restrictedusers={$service->restrictedusers}\n";

// Register context function on main portal API service.
$fn = 'local_btiportal_get_current_user_context';
if (!$DB->record_exists('external_functions', ['name' => $fn])) {
    echo "ERROR: {$fn} not in external_functions — run Moodle upgrade first.\n";
    exit(1);
}
if (!$DB->record_exists('external_services_functions', ['externalserviceid' => $service->id, 'functionname' => $fn])) {
    $DB->insert_record('external_services_functions', (object)[
        'externalserviceid' => $service->id,
        'functionname' => $fn,
    ]);
    echo "Added {$fn} to service {$service->shortname}\n";
} else {
    echo "{$fn} already on service {$service->shortname}\n";
}

$managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
$teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$syscontext = context_system::instance();
$coursecontext = context_course::instance($courseid);

foreach ($accounts as $username => $spec) {
    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $user = (object)[
            'username' => $username,
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'firstname' => $spec['firstname'],
            'lastname' => $spec['lastname'],
            'email' => $spec['email'],
        ];
        $user->id = user_create_user($user, false, false);
        echo "Created user {$username} id={$user->id}\n";
    } else {
        echo "User {$username} exists id={$user->id}\n";
    }

    update_internal_user_password($user, $spec['password']);
    echo "  password reset\n";

    if (!empty($spec['systemrole'])) {
        $role = $DB->get_record('role', ['shortname' => $spec['systemrole']], '*', MUST_EXIST);
        if (!$DB->record_exists('role_assignments', ['userid' => $user->id, 'roleid' => $role->id, 'contextid' => $syscontext->id])) {
            role_assign($role->id, $user->id, $syscontext->id);
            echo "  assigned system role {$spec['systemrole']}\n";
        }
    }

    if (!empty($spec['courserole'])) {
        $role = $spec['courserole'] === 'editingteacher' ? $teacherrole : $studentrole;
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
                $enrol->enrol_user($manual, $user->id, $role->id);
                echo "  enrolled on course {$courseid} as {$spec['courserole']}\n";
            } else {
                role_assign($role->id, $user->id, $coursecontext->id);
                echo "  ensured course role {$spec['courserole']} on course {$courseid}\n";
            }
        }
    }

    if ((int)$service->restrictedusers === 1) {
        if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id])) {
            $DB->insert_record('external_services_users', (object)[
                'externalserviceid' => $service->id,
                'userid' => $user->id,
                'timecreated' => time(),
            ]);
            echo "  added to Authorised users for {$service->shortname}\n";
        }
    }
}

echo "\nPortal test credentials (bti_portal_api / Authorised users when restricted):\n";
foreach ($accounts as $username => $spec) {
    echo "  {$username} / {$spec['password']}\n";
}
echo "Course id for instructor+student: {$courseid}\n";
echo "Service restrictedusers={$service->restrictedusers} (1 = Authorised users only)\n";
