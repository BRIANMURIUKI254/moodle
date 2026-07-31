<?php
/**
 * Reproduce password-change failures for brianmuriuki03 and prove the fix.
 * Does not print tokens. Sets a known temp password, then exercises WS.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

$username = 'brianmuriuki03';
$temp = 'TempPass1!';
$weak = 'password';
$strong = 'Welcome2#Ok';

$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);
cli_writeln("User id={$user->id} username={$user->username}");

cli_writeln('Site policy: length=' . (int)$CFG->minpasswordlength
    . ' digits=' . (int)$CFG->minpassworddigits
    . ' lower=' . (int)$CFG->minpasswordlower
    . ' upper=' . (int)$CFG->minpasswordupper
    . ' special=' . (int)$CFG->minpasswordnonalphanum);

// Reset to known temporary password (simulates welcome-email password).
update_internal_user_password($user, $temp);
set_user_preference('auth_forcepasswordchange', 1, $user);
cli_writeln('Set temporary password TempPass1! and forcepasswordchange=1');

// Confirm validate works.
$user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
cli_writeln('Temp password validates: ' . (validate_internal_user_password($user, $temp) ? 'yes' : 'no'));

$errmsg = '';
$weakok = check_password_policy($weak, $errmsg, $user);
cli_writeln('Weak password "password" policy ok=' . ($weakok ? 'yes' : 'no') . ' errmsg=' . trim(strip_tags($errmsg)));

$errmsg = '';
$strongok = check_password_policy($strong, $errmsg, $user);
cli_writeln('Strong password policy ok=' . ($strongok ? 'yes' : 'no'));

// Get user token via login/token.php logic without printing it.
$service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', MUST_EXIST);
\core\session\manager::set_user($user);

// Call external function directly as the user (same code path as WS).
$api = '\local_btiportal\external\change_own_password';

cli_writeln('--- Reproduce: wrong current password ---');
try {
    $api::execute('WrongPass1!', $strong);
    cli_writeln('UNEXPECTED success');
} catch (Throwable $e) {
    cli_writeln('errorcode=' . (property_exists($e, 'errorcode') ? $e->errorcode : get_class($e)));
    cli_writeln('message=' . $e->getMessage());
}

cli_writeln('--- Reproduce: weak new password (policy) ---');
try {
    $api::execute($temp, $weak);
    cli_writeln('UNEXPECTED success');
} catch (Throwable $e) {
    cli_writeln('errorcode=' . (property_exists($e, 'errorcode') ? $e->errorcode : get_class($e)));
    cli_writeln('message=' . $e->getMessage());
}

cli_writeln('--- Fix path: correct temp + strong new password ---');
try {
    $result = $api::execute($temp, $strong);
    cli_writeln('changed=' . (!empty($result['changed']) ? 'yes' : 'no') . ' userid=' . $result['userid']);
} catch (Throwable $e) {
    cli_writeln('FAILED: ' . $e->getMessage());
    exit(1);
}

$user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
$force = get_user_preferences('auth_forcepasswordchange', false, $user);
cli_writeln('forcepasswordchange after change=' . ($force ? '1' : '0'));
cli_writeln('New password validates: ' . (validate_internal_user_password($user, $strong) ? 'yes' : 'no'));
cli_writeln('Old temp still validates: ' . (validate_internal_user_password($user, $temp) ? 'yes' : 'no'));

// Re-login proof without network (wwwroot may not resolve inside the container).
$authuser = authenticate_user_login($username, $strong);
cli_writeln('Re-login with new password: ' . ($authuser && (int)$authuser->id === (int)$user->id ? 'SUCCESS' : 'FAILED'));
if (!$authuser) {
    exit(1);
}

cli_writeln('Done. brianmuriuki03 password is now Welcome2#Ok for portal testing.');
