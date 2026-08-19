<?php
/**
 * Create or update the BTI Portal Moodle Site Administrator account.
 *
 * Does not print passwords. Run on the Moodle server:
 *   php local/btiportal/cli/ensure_portal_admin_user.php --username=lms_admin --email=user@example.com
 *   php local/btiportal/cli/ensure_portal_admin_user.php --username=lms_admin --password='...' --email=user@example.com
 */
define('CLI_SCRIPT', true);

require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->dirroot . '/user/lib.php');

use local_btiportal\service_auth_helper;

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'username' => 'lms_admin',
        'password' => '',
        'email' => '',
        'firstname' => 'BTI',
        'lastname' => 'Portal Admin',
        'ignore-password-policy' => false,
    ],
    [
        'h' => 'help',
        'u' => 'username',
        'p' => 'password',
        'e' => 'email',
        'f' => 'firstname',
        'l' => 'lastname',
        'i' => 'ignore-password-policy',
    ]
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help']) {
    cli_writeln('Create or update a Moodle Site Administrator for the BTI Portal.');
    cli_writeln('Options:');
    cli_writeln('  --username=NAME     Moodle username (default: lms_admin)');
    cli_writeln('  --password=PASS     Set password (prompted if omitted on create/update)');
    cli_writeln('  --email=EMAIL       User email (required when creating)');
    cli_writeln('  --firstname=NAME    First name (default: BTI)');
    cli_writeln('  --lastname=NAME     Last name (default: Portal Admin)');
    cli_writeln('  --ignore-password-policy  Skip Moodle password policy checks');
    exit(0);
}

global $DB;

$username = core_text::strtolower(trim((string) $options['username']));
if ($username === '') {
    cli_error('Username is required.');
}

$email = trim((string) $options['email']);
$password = (string) $options['password'];
$ignorepolicy = !empty($options['ignore-password-policy']);

$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', IGNORE_MISSING);
$created = false;

if (!$user) {
    if ($email === '') {
        cli_error('Email is required when creating a new user.');
    }
    if ($password === '') {
        cli_writeln('Enter password for new user (input hidden):');
        $password = cli_input('', true);
    }
    if ($password === '') {
        cli_error('Password is required when creating a new user.');
    }

    $record = (object) [
        'username' => $username,
        'password' => $password,
        'firstname' => trim((string) $options['firstname']) ?: 'BTI',
        'lastname' => trim((string) $options['lastname']) ?: 'Portal Admin',
        'email' => $email,
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
    ];
    $userid = user_create_user($record, $ignorepolicy, false);
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    $created = true;
    cli_writeln("Created user id={$userid} username={$username}");
} else {
    $updates = [];
    if ($email !== '' && $user->email !== $email) {
        $updates['email'] = $email;
    }
    if (trim((string) $options['firstname']) !== '' && $user->firstname !== $options['firstname']) {
        $updates['firstname'] = trim((string) $options['firstname']);
    }
    if (trim((string) $options['lastname']) !== '' && $user->lastname !== $options['lastname']) {
        $updates['lastname'] = trim((string) $options['lastname']);
    }
    if ($updates) {
        $updates['id'] = $user->id;
        user_update_user((object) $updates, false, false);
        cli_writeln('Updated profile fields: ' . implode(', ', array_keys($updates)));
    }

    if ($password !== '') {
        if (!update_internal_user_password($user, $password, $ignorepolicy)) {
            cli_error('Failed to update password.');
        }
        cli_writeln('Password updated.');
    } else {
        cli_writeln('Password unchanged (pass --password to set).');
    }
    $user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
}

$admins = array_filter(array_map('intval', explode(',', (string) get_config(null, 'siteadmins'))));
$userid = (int) $user->id;
if (!in_array($userid, $admins, true)) {
    $admins[] = $userid;
    set_config('siteadmins', implode(',', $admins));
    cli_writeln("Added userid={$userid} to siteadmins.");
} else {
    cli_writeln("userid={$userid} is already a Site Administrator.");
}

$auth = service_auth_helper::ensure_portal_authorised($userid);
cli_writeln(
    'Portal API authorised=' . ($auth['authorised'] ? 'yes' : 'no')
    . ($auth['created'] ? ' (added to Authorised users)' : '')
);

$service = $DB->get_record('external_services', ['shortname' => service_auth_helper::PORTAL_SERVICE_SHORTNAME], '*', IGNORE_MISSING);
if ($service && !service_auth_helper::site_admin_has_valid_portal_token($userid, (int) $service->id)) {
    \core_external\util::generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $userid,
        \context_system::instance(),
        0,
        '',
        'BTI Portal API (site administrator)'
    );
    cli_writeln('Created permanent bti_portal_api token for portal login.');
} else {
    cli_writeln('Portal login token already exists.');
}

cli_writeln(
    'Done.'
    . ($created ? ' New Site Administrator ready for portal login.' : ' Site Administrator account ready.')
    . " username={$username}"
    . ($email !== '' ? " email={$email}" : " email={$user->email}")
);
