<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/webservice/lib.php');
require_once($CFG->libdir . '/externallib.php');

$username = $argv[1] ?? 'brianmuriuki03';
$known = $argv[2] ?? 'ResetPass1!';
$newpass = $argv[3] ?? 'NewPortal4#';

global $DB;

$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);
echo "User {$user->id} ({$user->username}) auth={$user->auth}\n";

update_internal_user_password($user, $known);
$user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
echo 'After reset, known password validates: ' . (validate_internal_user_password($user, $known) ? 'yes' : 'no') . "\n";

// Simulate portal WS password change (same code path as local_btiportal_change_own_password).
\core\session\manager::set_user($user);
$changed = \local_btiportal\external\change_own_password::execute($known, $newpass);
echo 'change_own_password: ' . json_encode($changed) . "\n";

$user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
echo 'New password validates internally: ' . (validate_internal_user_password($user, $newpass) ? 'yes' : 'no') . "\n";
echo 'Old password still validates: ' . (validate_internal_user_password($user, $known) ? 'yes' : 'no') . "\n";

// Simulate token.php authentication (what the portal uses on login).
$authuser = authenticate_user_login($username, $newpass);
echo 'authenticate_user_login(new): ' . ($authuser ? 'success id=' . $authuser->id : 'failed') . "\n";
$authold = authenticate_user_login($username, $known);
echo 'authenticate_user_login(old): ' . ($authold ? 'success' : 'failed') . "\n";

echo "Try portal login with password: {$newpass}\n";
