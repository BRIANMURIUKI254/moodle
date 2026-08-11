<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;

$svc = $DB->get_record('external_services', ['shortname' => 'bti_portal_api']);
echo 'svc_id=' . ($svc->id ?? 0) . ' restricted=' . ($svc->restrictedusers ?? '?') . PHP_EOL;
if ($svc) {
    $users = $DB->get_records('external_services_users', ['externalserviceid' => $svc->id]);
    echo 'authorized_users=' . count($users) . PHP_EOL;
    foreach ($users as $u) {
        echo "uid={$u->userid}\n";
    }
}

foreach (['admin', 'portal_instructor', 'portal_student'] as $username) {
    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if (!$user) {
        echo "MISSING {$username}\n";
        continue;
    }
    $ctx = context_system::instance();
    $can = has_capability('moodle/webservice:createtoken', $ctx, $user->id);
    echo "{$username}#{$user->id} createtoken=" . ($can ? '1' : '0') . PHP_EOL;

    if ($svc && (int)$svc->restrictedusers === 1) {
        $exists = $DB->record_exists('external_services_users', [
            'externalserviceid' => $svc->id,
            'userid' => $user->id,
        ]);
        if (!$exists) {
            $DB->insert_record('external_services_users', (object)[
                'externalserviceid' => $svc->id,
                'userid' => $user->id,
                'timecreated' => time(),
            ]);
            echo "AUTHORIZED {$username}\n";
        } else {
            echo "ALREADY_AUTHORIZED {$username}\n";
        }
    }
}

// Ensure createtoken for authenticated user role if missing for these accounts.
$authrole = $DB->get_record('role', ['shortname' => 'user']);
if ($authrole) {
    $sysctx = context_system::instance();
    role_change_permission($authrole->id, $sysctx, 'moodle/webservice:createtoken', CAP_ALLOW);
    echo "GRANTED createtoken to authenticated user role\n";
}

echo "DONE\n";
