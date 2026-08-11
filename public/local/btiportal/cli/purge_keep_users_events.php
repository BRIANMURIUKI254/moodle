<?php
/**
 * Wipe operational / academic data while keeping:
 * - Moodle user accounts (students, teachers, admins)
 * - Portal events (local_btiportal_event)
 * - Site course (id = 1)
 *
 * Usage: php public/local/btiportal/cli/purge_keep_users_events.php
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use local_btiportal\moodle_course_cleanup_helper;

cli_heading('BTI Portal wipe (keep users + events)');

$dbman = $DB->get_manager();

// Tables to KEEP (not truncated).
$keep = [
    'local_btiportal_event' => true,
];

// Child-first delete order for local_btiportal tables.
$purgeorder = [
    'local_btiportal_resource_completion',
    'local_btiportal_resource',
    'local_btiportal_recording',
    'local_btiportal_certificate',
    'local_btiportal_assignment_meta',
    'local_btiportal_inactive_alert',
    'local_btiportal_notification',
    'local_btiportal_announcement',
    'local_btiportal_timetable',
    'local_btiportal_unit_gradeitem',
    'local_btiportal_unit',
    'local_btiportal_student_elective_sel',
    'local_btiportal_elective_group_unit',
    'local_btiportal_elective_group',
    'local_btiportal_course_unit_prerequisite',
    'local_btiportal_course_unit_teacher',
    'local_btiportal_course_unit',
    'local_btiportal_course_request',
    'local_btiportal_student_acad_hist',
    'local_btiportal_student_profile',
    'local_btiportal_enrol_ops',
    'local_btiportal_op_audit',
    'local_btiportal_progression_rule',
    'local_btiportal_lecturer_dept',
    'local_btiportal_staff_org',
    'local_btiportal_curriculum_semester',
    'local_btiportal_curriculum_year',
    'local_btiportal_curriculum',
    'local_btiportal_programme',
    'local_btiportal_department',
    'local_btiportal_school',
    'local_btiportal_semester',
    'local_btiportal_academic_year',
];

cli_writeln('1) Clearing local_btiportal tables (keeping events)…');
$seen = [];
foreach ($purgeorder as $table) {
    $seen[$table] = true;
    if (!$dbman->table_exists($table) || !empty($keep[$table])) {
        continue;
    }
    $count = (int)$DB->count_records($table);
    $DB->delete_records($table);
    cli_writeln("  {$table}: cleared {$count}");
}

// Catch any other local_btiportal_* tables added later (still keep events).
$alltables = $DB->get_tables(false);
foreach ($alltables as $table) {
    if (!str_starts_with($table, 'local_btiportal_')) {
        continue;
    }
    if (!empty($keep[$table]) || !empty($seen[$table])) {
        continue;
    }
    $count = (int)$DB->count_records($table);
    $DB->delete_records($table);
    cli_writeln("  {$table}: cleared {$count} (extra)");
}

$keptEvents = $dbman->table_exists('local_btiportal_event')
    ? (int)$DB->count_records('local_btiportal_event')
    : 0;
cli_writeln("  Kept local_btiportal_event rows: {$keptEvents}");

cli_writeln('2) Deleting Moodle courses (except site course)…');
$courses = $DB->get_records_select('course', 'id > ?', [SITEID], 'id ASC');
cli_writeln('  Courses to delete: ' . count($courses));
foreach ($courses as $course) {
    cli_writeln("  Deleting #{$course->id} ({$course->shortname})…");
    try {
        $result = moodle_course_cleanup_helper::delete_moodle_course((int)$course->id);
        cli_writeln('    deleted=' . (!empty($result['deleted']) ? 'yes' : 'no'));
    } catch (Throwable $e) {
        // Fallback to core delete if helper fails mid-way.
        try {
            delete_course($course, false);
            cli_writeln('    deleted via core fallback');
        } catch (Throwable $e2) {
            cli_writeln('    FAILED: ' . $e2->getMessage());
        }
    }
}

cli_writeln('3) Clearing Moodle messaging / notifications (accounts kept)…');
$msgtables = [
    'message_user_actions',
    'message_conversation_actions',
    'message_conversation_members',
    'message_conversations',
    'message_contact_requests',
    'message_contacts',
    'message_email_messages',
    'messages',
    'notifications',
];
foreach ($msgtables as $table) {
    if (!$dbman->table_exists($table)) {
        continue;
    }
    $count = (int)$DB->count_records($table);
    if ($count > 0) {
        $DB->delete_records($table);
        cli_writeln("  {$table}: cleared {$count}");
    }
}

// Keep Moodle calendar site events? User asked to keep "event" — leave core {event} alone
// except course-scoped events for deleted courses (already removed with courses).

$usercount = (int)$DB->count_records('user', ['deleted' => 0]);
cli_writeln("4) User accounts retained: {$usercount} (not deleted)");

purge_all_caches();
cli_writeln('Done. Kept: user accounts + portal events + site course.');
cli_writeln('Removed: Moodle courses, academic catalogue, placements, timetable, messages, etc.');
