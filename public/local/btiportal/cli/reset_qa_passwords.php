<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/authlib.php');

global $DB;

$pass = 'PortalQA!2026';
$usernames = ['admin', 'portal_instructor', 'portal_student'];

foreach ($usernames as $username) {
    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if (!$user) {
        echo "MISSING {$username}\n";
        continue;
    }
    $user->password = hash_internal_user_password($pass);
    $DB->update_record('user', $user);
    unset_user_preference('auth_forcepasswordchange', $user);
    echo "RESET {$username}#{$user->id}\n";
}
echo "PASSWORD_SET=1\n";
