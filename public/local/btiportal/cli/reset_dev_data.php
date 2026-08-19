<?php
/**
 * Development-only business data reset. Does not drop databases or tables.
 */
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $DB, $CFG;

$confirm = in_array('--confirm-reset', $argv, true);
if (!$confirm) {
    echo "Refusing to run without --confirm-reset\n";
    exit(1);
}

require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/messagelib.php');

set_config('coursebinenable', 0, 'tool_recyclebin');
set_config('categorybinenable', 0, 'tool_recyclebin');

$keepuserids = [1, 2, 3, 14]; // guest, site admin, API service, portal_admin
$site = get_site();
$keepcourseids = [(int)$site->id];

$stats = [
    'plugin_rows_deleted' => 0,
    'courses_deleted' => 0,
    'users_deleted' => 0,
    'enrolments_removed' => 0,
    'categories_deleted' => 0,
    'file_areas_cleared' => 0,
    'notifications_deleted' => 0,
];

$plugintables = [];
foreach ($DB->get_tables() as $table) {
    if (strpos($table, 'local_btiportal') === 0) {
        $plugintables[] = $table;
    }
}
sort($plugintables);

// Child-ish tables first; unknown plugin tables still get deleted after this list.
$deleteorder = [
    'local_btiportal_resource_completion',
    'local_btiportal_resource',
    'local_btiportal_student_elective_sel',
    'local_btiportal_elective_group_unit',
    'local_btiportal_elective_group',
    'local_btiportal_course_unit_prerequisite',
    'local_btiportal_course_unit_teacher',
    'local_btiportal_curriculum_unit',
    'local_btiportal_student_acad_hist',
    'local_btiportal_student_profile',
    'local_btiportal_lecturer_dept',
    'local_btiportal_staff_org',
    'local_btiportal_timetable',
    'local_btiportal_assignment_meta',
    'local_btiportal_unit_gradeitem',
    'local_btiportal_unit',
    'local_btiportal_enrol_ops',
    'local_btiportal_op_audit',
    'local_btiportal_certificate',
    'local_btiportal_course_request',
    'local_btiportal_announcement',
    'local_btiportal_notification',
    'local_btiportal_event',
    'local_btiportal_recording',
    'local_btiportal_inactive_alert',
    'local_btiportal_progression_rule',
    'local_btiportal_curriculum_semester',
    'local_btiportal_curriculum_year',
    'local_btiportal_course_unit',
    'local_btiportal_curriculum',
    'local_btiportal_semester',
    'local_btiportal_academic_year',
    'local_btiportal_programme',
    'local_btiportal_department',
    'local_btiportal_school',
];

$seen = [];
$ordered = [];
foreach ($deleteorder as $table) {
    if (in_array($table, $plugintables, true)) {
        $ordered[] = $table;
        $seen[$table] = true;
    }
}
foreach ($plugintables as $table) {
    if (empty($seen[$table])) {
        $ordered[] = $table;
    }
}

echo "===== DELETE local_btiportal business rows =====\n";
foreach ($ordered as $table) {
    $before = (int)$DB->count_records($table);
    if ($before > 0) {
        $DB->delete_records($table);
    }
    $after = (int)$DB->count_records($table);
    $deleted = $before - $after;
    $stats['plugin_rows_deleted'] += $deleted;
    echo "{$table}: deleted={$deleted} remaining={$after}\n";
}

echo "\n===== Detach Zoom meetings from API before course delete =====\n";
if ($DB->get_manager()->table_exists('zoom')) {
    $zooms = $DB->get_records('zoom');
    foreach ($zooms as $zoom) {
        echo "Clearing Zoom instance {$zoom->id} in course {$zoom->course}\n";
        $zoom->meeting_id = 0;
        $zoom->join_url = '';
        if (property_exists($zoom, 'exists_on_zoom')) {
            $zoom->exists_on_zoom = 0;
        }
        $DB->update_record('zoom', $zoom);
    }
}

echo "\n===== DELETE Moodle test courses =====\n";
$courses = $DB->get_records_select('course', 'id > 1', [], 'id ASC');
foreach ($courses as $course) {
    if (in_array((int)$course->id, $keepcourseids, true)) {
        continue;
    }
    echo "Deleting course {$course->id} {$course->shortname}\n";
    try {
        delete_course($course, false);
        $stats['courses_deleted']++;
    } catch (Throwable $e) {
        echo "delete_course error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
fix_course_sortorder();

echo "\n===== DELETE Moodle test users =====\n";
$users = $DB->get_records_select('user', 'deleted = 0 AND id > 1', [], 'id ASC');
foreach ($users as $user) {
    if (in_array((int)$user->id, $keepuserids, true)) {
        echo "KEEP id={$user->id} {$user->username}\n";
        continue;
    }
    if (is_siteadmin($user->id)) {
        echo "KEEP siteadmin id={$user->id} {$user->username}\n";
        continue;
    }
    echo "Deleting user {$user->id} {$user->username}\n";
    delete_user($user);
    $stats['users_deleted']++;
}

echo "\n===== DELETE empty test course categories =====\n";
$cats = $DB->get_records_select('course_categories', 'id > 1', [], 'depth DESC, id DESC');
foreach ($cats as $cat) {
    $coursecount = $DB->count_records('course', ['category' => $cat->id]);
    $childcount = $DB->count_records('course_categories', ['parent' => $cat->id]);
    if ($coursecount === 0 && $childcount === 0) {
        echo "Deleting category {$cat->id} {$cat->name}\n";
        $DB->delete_records('course_categories', ['id' => $cat->id]);
        $stats['categories_deleted']++;
    } else {
        echo "KEEP category {$cat->id} {$cat->name} courses={$coursecount} children={$childcount}\n";
    }
}

echo "\n===== CLEAR portal file areas =====\n";
$fs = get_file_storage();
$syscontext = context_system::instance();
foreach (['eventimage', 'courseimage', 'certificate', 'programmeimage', 'courseunitimage', 'schoolimage', 'departmentimage'] as $filearea) {
    $fs->delete_area_files($syscontext->id, 'local_btiportal', $filearea);
    $stats['file_areas_cleared']++;
    echo "cleared local_btiportal/{$filearea}\n";
}

echo "\n===== CLEAR leftover notifications =====\n";
if ($DB->get_manager()->table_exists('notifications')) {
    $stats['notifications_deleted'] = (int)$DB->count_records('notifications');
    $DB->delete_records('notifications');
}
if ($DB->get_manager()->table_exists('message_popup_notifications')) {
    $DB->delete_records('message_popup_notifications');
}

echo "\n===== Purge caches =====\n";
purge_caches();

echo "\n===== RESET STATS =====\n";
foreach ($stats as $k => $v) {
    echo "{$k}={$v}\n";
}
echo "DONE\n";
