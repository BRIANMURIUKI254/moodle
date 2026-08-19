<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\student_course_access_helper;
use local_btiportal\student_profile_helper;

global $DB;

$ids = array_slice($argv, 1);
$users = [];
if ($ids) {
    foreach ($ids as $id) {
        $user = $DB->get_record('user', ['id' => (int)$id, 'deleted' => 0]);
        if ($user) {
            $users[] = $user;
        }
    }
} else {
    $users = $DB->get_records_select('user', 'deleted = 0 AND id > 2', [], 'id ASC', '*', 0, 50);
}

foreach ($users as $user) {
    $profile = student_profile_helper::get_by_userid((int)$user->id);
    if (!$profile) {
        continue;
    }
    echo "\n===== REPAIR USER {$user->id} {$user->username} {$user->email} =====\n";
    $result = student_course_access_helper::ensure_pathway_enrolments((int)$user->id);
    echo "enrolled={$result['enrolled']} reused={$result['reused']} failed={$result['failed']}\n";
    echo "Course Unit | Moodle ID | Moodle enrolment | Active | Accessible\n";
    foreach ($result['items'] as $item) {
        echo sprintf(
            "%s | %s | %s | %s | %s | %s\n",
            $item['code'] ?: '-',
            $item['moodlecourseid'] ?: '-',
            ((int)$item['enrolled'] === 1) ? 'YES' : 'NO',
            ((int)$item['enrolled'] === 1) ? 'YES' : 'NO',
            ((int)$item['accessible'] === 1) ? 'YES' : 'NO',
            $item['accesscode']
        );
    }
}
