<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\curriculum_unit_helper;
use local_btiportal\student_profile_helper;
use local_btiportal\student_unit_pathway_helper;

global $DB;

$users = $DB->get_records_select('user', "id IN (31, 32) AND deleted = 0", [], 'id ASC', 'id, username, email, firstname, lastname');
if (!$users) {
    $users = $DB->get_records_select('user', "deleted = 0 AND id > 2", [], 'id ASC', 'id, username, email, firstname, lastname', 0, 10);
}

foreach ($users as $user) {
    echo "\n===== USER {$user->id} {$user->username} {$user->email} =====\n";
    $profile = student_profile_helper::get_by_userid((int)$user->id);
    if (!$profile) {
        echo "no student profile\n";
        continue;
    }
    echo "profileid={$profile['id']} curriculumid={$profile['curriculumid']} programmeid={$profile['programmeid']}\n";
    $pathway = student_unit_pathway_helper::get_pathway((int)$user->id);
    $all = array_merge($pathway['current'], $pathway['studied'], $pathway['upcoming']);
    echo "pathway_count=" . count($all) . "\n";

    $contextok = function (int $userid, int $courseid): string {
        try {
            $course = get_course($courseid);
            $context = context_course::instance($courseid);
            $enrolled = is_enrolled($context, $userid, '', true);
            $canaccess = can_access_course($course, core_user::get_user($userid));
            return "enrolled_active=" . (int)$enrolled . " can_access=" . (int)$canaccess;
        } catch (Throwable $e) {
            return 'error=' . $e->getMessage();
        }
    };

    echo "Course Unit | Moodle ID | visible | enrol_inst | ue_status | role | access\n";
    foreach ($all as $unit) {
        $code = $unit['code'];
        $cid = (int)($unit['moodlecourseid'] ?? 0);
        $course = $cid > 1 ? $DB->get_record('course', ['id' => $cid], 'id,shortname,visible') : null;
        $inst = $cid > 1 ? $DB->get_record('enrol', ['courseid' => $cid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE) : null;
        $ue = ($inst && $user) ? $DB->get_record('user_enrolments', ['enrolid' => $inst->id, 'userid' => $user->id]) : null;
        $role = '';
        if ($cid > 1) {
            $ctx = $DB->get_record_sql(
                "SELECT r.shortname
                   FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                   JOIN {context} ctx ON ctx.id = ra.contextid
                  WHERE ra.userid = :uid AND ctx.contextlevel = 50 AND ctx.instanceid = :cid",
                ['uid' => $user->id, 'cid' => $cid]
            );
            $role = $ctx->shortname ?? '';
        }
        $access = $cid > 1 ? $contextok((int)$user->id, $cid) : 'unmapped';
        echo sprintf(
            "%s | %s | vis=%s | inst=%s/%s | ue=%s | role=%s | %s\n",
            $code,
            $cid ?: '-',
            $course ? (string)$course->visible : 'missing',
            $inst ? (string)$inst->status : 'none',
            $inst ? (string)$inst->enrol : 'none',
            $ue ? ('st=' . $ue->status . ' start=' . $ue->timestart . ' end=' . $ue->timeend) : 'NO',
            $role ?: '-',
            $access
        );
    }

    echo "ALL moodle enrolments:\n";
    $ues = $DB->get_records_sql(
        "SELECT ue.id, e.courseid, c.shortname, c.visible, e.status AS inststatus, ue.status AS uestatus, e.enrol
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {course} c ON c.id = e.courseid
          WHERE ue.userid = :uid
          ORDER BY e.courseid",
        ['uid' => $user->id]
    );
    foreach ($ues as $row) {
        echo "  course={$row->courseid} {$row->shortname} vis={$row->visible} inst={$row->inststatus} ue={$row->uestatus} method={$row->enrol}\n";
    }
}
