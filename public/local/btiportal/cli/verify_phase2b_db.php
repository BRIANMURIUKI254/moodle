<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');

$v = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_btiportal', 'name' => 'version']);
echo "PLUGIN_VERSION={$v}\n";

$tables = [
    'local_btiportal_programme',
    'local_btiportal_curriculum',
    'local_btiportal_academic_year',
    'local_btiportal_semester',
    'local_btiportal_course_unit',
    'local_btiportal_course_unit_prerequisite',
    'local_btiportal_course_unit_teacher',
];
foreach ($tables as $t) {
    echo $t . '=' . ($DB->get_manager()->table_exists($t) ? 'YES' : 'NO') . "\n";
}

$fns = [
    'local_btiportal_create_programme',
    'local_btiportal_create_academic_course_unit',
    'local_btiportal_assign_course_unit_teacher',
];
foreach ($fns as $fn) {
    echo "FN_{$fn}=" . ($DB->record_exists('external_functions', ['name' => $fn]) ? 'YES' : 'NO') . "\n";
}

foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
    $svc = $DB->get_record('external_services', ['shortname' => $shortname]);
    if (!$svc) {
        echo "SERVICE_{$shortname}=MISSING\n";
        continue;
    }
    echo "SERVICE_{$shortname}={$svc->id}\n";
    foreach ($fns as $fn) {
        $ok = $DB->record_exists('external_services_functions', [
            'externalserviceid' => $svc->id,
            'functionname' => $fn,
        ]);
        echo "ATTACHED_{$shortname}_{$fn}=" . ($ok ? 'YES' : 'NO') . "\n";
    }
}

$caps = ['local/btiportal:manageacademic', 'local/btiportal:viewacademic'];
foreach ($caps as $c) {
    echo 'CAP_' . $c . '=' . ($DB->record_exists('capabilities', ['name' => $c]) ? 'YES' : 'NO') . "\n";
}

$sysctx = context_system::instance()->id;
$roles = $DB->get_records_select('role', "shortname IN ('manager','coursecreator')");
foreach ($roles as $r) {
    foreach ($caps as $c) {
        $row = $DB->get_record('role_capabilities', [
            'roleid' => $r->id,
            'capability' => $c,
            'contextid' => $sysctx,
        ]);
        // Also check without context filter (defaults may be at system).
        if (!$row) {
            $row = $DB->get_record_select(
                'role_capabilities',
                'roleid = ? AND capability = ?',
                [$r->id, $c],
                '*',
                IGNORE_MULTIPLE
            );
        }
        if ($row) {
            echo "ROLE_{$r->shortname}_{$c}=permission={$row->permission};contextid={$row->contextid}\n";
        } else {
            echo "ROLE_{$r->shortname}_{$c}=NO_ROW\n";
        }
    }
}

// Sample course + non-general section for WS tests.
$course = $DB->get_record_sql(
    "SELECT c.id, c.shortname FROM {course} c WHERE c.id > 1 ORDER BY c.id ASC",
    [],
    IGNORE_MULTIPLE
);
if ($course) {
    echo "SAMPLE_COURSEID={$course->id};SHORTNAME={$course->shortname}\n";
    $section = $DB->get_record_sql(
        "SELECT id, section, name FROM {course_sections} WHERE course = ? AND section > 0 ORDER BY section ASC",
        [$course->id],
        IGNORE_MULTIPLE
    );
    if ($section) {
        echo "SAMPLE_SECTIONID={$section->id};SECTIONNUM={$section->section};NAME={$section->name}\n";
    } else {
        echo "SAMPLE_SECTIONID=NONE\n";
    }
    $gen = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0]);
    if ($gen) {
        echo "GENERAL_SECTIONID={$gen->id}\n";
    }
} else {
    echo "SAMPLE_COURSEID=NONE\n";
}

$user = $DB->get_record_sql(
    "SELECT id, username FROM {user} WHERE deleted = 0 AND id > 1 ORDER BY id ASC",
    [],
    IGNORE_MULTIPLE
);
if ($user) {
    echo "SAMPLE_USERID={$user->id};USERNAME={$user->username}\n";
}
