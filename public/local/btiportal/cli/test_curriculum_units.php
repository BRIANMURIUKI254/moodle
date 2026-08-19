<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\curriculum_unit_helper;

global $DB;

$admin = get_admin();
$userid = $admin ? (int)$admin->id : 2;

$int001 = $DB->get_record('local_btiportal_course_unit', ['code' => 'INT001']);
$moodle7 = $DB->record_exists('course', ['id' => 7]);
$moodle8 = $DB->get_record('course', ['id' => 8], 'id,shortname');
$beforecourses = $DB->count_records_select('course', 'id > 1');
$beforeunits = $DB->count_records('local_btiportal_course_unit');

echo 'int001_exists=' . ($int001 ? '1' : '0') . PHP_EOL;
echo 'int001_courseid=' . ($int001->courseid ?? 0) . PHP_EOL;
echo 'moodle_7_exists=' . ($moodle7 ? '1' : '0') . PHP_EOL;
echo 'moodle_8_shortname=' . ($moodle8->shortname ?? '') . PHP_EOL;

$curr1 = curriculum_unit_helper::list_assignments(1);
echo 'curriculum_1_units=' . count($curr1) . PHP_EOL;
foreach ($curr1 as $row) {
    echo "curr1 {$row['code']} y{$row['yearofstudy']}s{$row['semestersequence']} moodle={$row['courseid']}\n";
}

$result = curriculum_unit_helper::save_assignments(2, [
    ['code' => 'MAT101', 'name' => 'Mathematics I', 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 1, 'unittype' => 'core', 'isrequired' => 1, 'credithours' => 3],
    ['code' => 'COM101', 'name' => 'Communication Skills', 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 2, 'unittype' => 'core', 'isrequired' => 1, 'credithours' => 3],
    ['code' => 'CSC103', 'name' => 'Data Structures', 'yearofstudy' => 1, 'semestersequence' => 2, 'sortorder' => 1, 'unittype' => 'core', 'isrequired' => 1, 'credithours' => 3],
], $userid);
echo 'save_ele_ok=' . (!empty($result['ok']) ? '1' : '0') . PHP_EOL;
echo 'save_ele_error=' . ($result['errors'][0]['message'] ?? '') . PHP_EOL;
echo 'save_ele_count=' . ($result['record']['count'] ?? 0) . PHP_EOL;

$dup = curriculum_unit_helper::save_assignments(2, [
    ['courseunitid' => (int)$int001->id, 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 1, 'unittype' => 'core', 'isrequired' => 1],
    ['courseunitid' => (int)$int001->id, 'yearofstudy' => 1, 'semestersequence' => 2, 'sortorder' => 2, 'unittype' => 'core', 'isrequired' => 1],
], $userid);
echo 'duplicate_rejected=' . (empty($dup['ok']) ? '1' : '0') . PHP_EOL;

$share = curriculum_unit_helper::save_assignments(2, array_merge($result['record']['items'] ?? [], [[
    'courseunitid' => (int)$int001->id,
    'code' => 'INT001',
    'yearofstudy' => 2,
    'semestersequence' => 1,
    'sortorder' => 10,
    'unittype' => 'core',
    'isrequired' => 1,
]]), $userid);
echo 'share_int001_ok=' . (!empty($share['ok']) ? '1' : '0') . PHP_EOL;
echo 'share_int001_count=' . ($share['record']['count'] ?? 0) . PHP_EOL;

$int001after = $DB->get_record('local_btiportal_course_unit', ['code' => 'INT001']);
$aftercourses = $DB->count_records_select('course', 'id > 1');
$afterunits = $DB->count_records('local_btiportal_course_unit');
$int001count = $DB->count_records('local_btiportal_course_unit', ['code' => 'INT001']);
$matmoodle = $DB->get_record('course', ['shortname' => 'MAT101'], 'id,shortname');

echo 'int001_courseid_after=' . ($int001after->courseid ?? 0) . PHP_EOL;
echo 'int001_unit_rows=' . $int001count . PHP_EOL;
echo 'moodle_7_still=' . ($DB->record_exists('course', ['id' => 7]) ? '1' : '0') . PHP_EOL;
echo 'moodle_8_still=' . ($DB->record_exists('course', ['id' => 8]) ? '1' : '0') . PHP_EOL;
echo 'mat101_moodle_id=' . ($matmoodle->id ?? 0) . PHP_EOL;
echo 'course_count_before=' . $beforecourses . ' after=' . $aftercourses . PHP_EOL;
echo 'unit_count_before=' . $beforeunits . ' after=' . $afterunits . PHP_EOL;

$reload = curriculum_unit_helper::list_assignments(2);
echo 'reload_count=' . count($reload) . PHP_EOL;

$ok = !empty($result['ok'])
    && (int)($result['record']['count'] ?? 0) >= 3
    && empty($dup['ok'])
    && (int)($int001after->courseid ?? 0) === 8
    && $DB->record_exists('course', ['id' => 7])
    && $DB->record_exists('course', ['id' => 8])
    && $int001count === 1
    && count($reload) >= 3;
exit($ok ? 0 : 1);
