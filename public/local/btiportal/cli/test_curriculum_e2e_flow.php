<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\curriculum_unit_helper;
use local_btiportal\student_unit_pathway_helper;
use local_btiportal\academic_enrolment_helper;
use local_btiportal\lecturer_teaching_helper;
use local_btiportal\course_unit_teacher_helper;

global $DB;

function codes(array $items): array {
    $out = [];
    foreach ($items as $item) {
        $out[] = strtoupper((string)($item['code'] ?? ''));
    }
    sort($out);
    return $out;
}

function fail(string $msg): void {
    echo "FAIL $msg\n";
    exit(1);
}

$adminid = (int)$DB->get_field_sql("SELECT id FROM {user} WHERE username = 'admin' AND deleted = 0", [], IGNORE_MISSING);
if ($adminid <= 0) {
    $adminid = 2;
}

$beforecourses = (int)$DB->count_records_select('course', 'id > 1');
$int001 = $DB->get_record('local_btiportal_course_unit', ['code' => 'INT001'], '*', MUST_EXIST);
$mat101 = $DB->get_record('local_btiportal_course_unit', ['code' => 'MAT101'], '*', MUST_EXIST);
$csc103 = $DB->get_record('local_btiportal_course_unit', ['code' => 'CSC103'], '*', MUST_EXIST);
$com101 = $DB->get_record('local_btiportal_course_unit', ['code' => 'COM101'], '*', MUST_EXIST);

echo "PRESERVE_BEFORE INT001={$int001->courseid} MAT101={$mat101->courseid} ELE001="
    . (int)$DB->record_exists('course', ['id' => 7, 'shortname' => 'ELE001']) . PHP_EOL;

$four = [
    ['courseunitid' => (int)$int001->id, 'code' => 'INT001', 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 1, 'unittype' => 'core', 'isrequired' => 1],
    ['courseunitid' => (int)$mat101->id, 'code' => 'MAT101', 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 2, 'unittype' => 'core', 'isrequired' => 1],
    ['courseunitid' => (int)$csc103->id, 'code' => 'CSC103', 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 3, 'unittype' => 'core', 'isrequired' => 1],
    ['courseunitid' => (int)$com101->id, 'code' => 'COM101', 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 4, 'unittype' => 'core', 'isrequired' => 1],
];

$save = curriculum_unit_helper::save_assignments(1, $four, $adminid);
if (empty($save['ok'])) {
    fail('save4 ' . json_encode($save['errors'] ?? []));
}
$reload = curriculum_unit_helper::list_assignments(1);
$reloadcodes = codes($reload);
echo 'SAVE4 count=' . count($reload) . ' codes=' . implode(',', $reloadcodes) . PHP_EOL;
if ($reloadcodes !== ['COM101', 'CSC103', 'INT001', 'MAT101']) {
    fail('save4 codes mismatch');
}

$dup = curriculum_unit_helper::save_assignments(1, array_merge($four, [
    ['courseunitid' => (int)$int001->id, 'code' => 'INT001', 'yearofstudy' => 1, 'semestersequence' => 2, 'sortorder' => 5, 'unittype' => 'core', 'isrequired' => 1],
]), $adminid);
echo 'DUP_SAME_CURR rejected=' . (empty($dup['ok']) ? '1' : '0') . ' msg=' . ($dup['errors'][0]['message'] ?? '') . PHP_EOL;
if (!empty($dup['ok'])) {
    fail('duplicate INT001 was accepted');
}

$three = array_values(array_filter($four, static fn(array $item): bool => $item['code'] !== 'COM101'));
$save3 = curriculum_unit_helper::save_assignments(1, $three, $adminid);
if (empty($save3['ok'])) {
    fail('save3 ' . json_encode($save3['errors'] ?? []));
}
$reload3 = curriculum_unit_helper::list_assignments(1);
echo 'REMOVE_COM101 count=' . count($reload3) . ' codes=' . implode(',', codes($reload3)) . PHP_EOL;
if (count($reload3) !== 3 || in_array('COM101', codes($reload3), true)) {
    fail('remove did not leave exactly 3');
}
if (!$DB->record_exists('local_btiportal_course_unit', ['id' => (int)$com101->id])) {
    fail('COM101 course unit was deleted');
}
if (!$DB->record_exists('course', ['id' => (int)$com101->courseid])) {
    fail('COM101 Moodle course was deleted');
}

$save4b = curriculum_unit_helper::save_assignments(1, $four, $adminid);
if (empty($save4b['ok'])) {
    fail('readd ' . json_encode($save4b['errors'] ?? []));
}
$reload4 = curriculum_unit_helper::list_assignments(1);
$aftercourses = (int)$DB->count_records_select('course', 'id > 1');
$com101after = $DB->get_record('local_btiportal_course_unit', ['code' => 'COM101'], '*', MUST_EXIST);
echo 'READD count=' . count($reload4) . ' codes=' . implode(',', codes($reload4))
    . " moodle_courses_before={$beforecourses} after={$aftercourses} COM101_moodle={$com101after->courseid}\n";
if (count($reload4) !== 4) {
    fail('readd did not restore 4 units');
}
if ($aftercourses !== $beforecourses) {
    fail("Moodle course count changed {$beforecourses} -> {$aftercourses}");
}
if ((int)$com101after->courseid !== (int)$com101->courseid) {
    fail('COM101 Moodle id changed on re-add');
}

$share = curriculum_unit_helper::list_assignments(2);
$sharecodes = codes($share);
echo 'SHARE_CURR2 count=' . count($share) . ' codes=' . implode(',', $sharecodes) . PHP_EOL;
if (!in_array('INT001', $sharecodes, true)) {
    echo "WARN curriculum 2 no longer lists INT001\n";
}

$int001after = $DB->get_record('local_btiportal_course_unit', ['code' => 'INT001'], '*', MUST_EXIST);
$mat101after = $DB->get_record('local_btiportal_course_unit', ['code' => 'MAT101'], '*', MUST_EXIST);
echo "PRESERVE_AFTER INT001={$int001after->courseid} MAT101={$mat101after->courseid} ELE001="
    . (int)$DB->record_exists('course', ['id' => 7, 'shortname' => 'ELE001']) . PHP_EOL;
if ((int)$int001after->courseid !== 8) {
    fail('INT001 remapped away from Moodle 8');
}
if ((int)$mat101after->courseid !== 9) {
    fail('MAT101 remapped away from Moodle 9');
}

$path = student_unit_pathway_helper::get_pathway(31);
$all = array_merge($path['current'] ?? [], $path['studied'] ?? [], $path['upcoming'] ?? []);
$studentcodes = codes($all);
echo 'STUDENT31_UNITS count=' . count($all) . ' current=' . count($path['current'] ?? [])
    . ' codes=' . implode(',', $studentcodes) . PHP_EOL;
if ($studentcodes !== ['COM101', 'CSC103', 'INT001', 'MAT101']) {
    fail('student 31 does not see all 4 curriculum units');
}

$preview = academic_enrolment_helper::preview(31, 'admission_enrolment');
$p = $preview['preview'] ?? [];
echo 'ENROL_PREVIEW already=' . count($p['alreadyenrolled'] ?? [])
    . ' toenrol=' . count($p['toenrol'] ?? [])
    . ' skipped=' . count($p['skipped'] ?? [])
    . ' canenrol=' . (int)($p['canenrol'] ?? 0) . PHP_EOL;
foreach (($p['skipped'] ?? []) as $row) {
    echo '  SKIPPED ' . ($row['code'] ?? '') . ' reason=' . ($row['reason'] ?? '') . PHP_EOL;
}
foreach (($p['toenrol'] ?? []) as $row) {
    echo '  TOENROL ' . ($row['code'] ?? '') . ' moodle=' . ($row['moodlecourseid'] ?? 0) . PHP_EOL;
}

$exec = academic_enrolment_helper::execute(31, 'admission_enrolment', [], $adminid);
$r = $exec['result'] ?? [];
echo 'ENROL_EXEC ok=' . (int)!empty($exec['ok']) . ' status=' . ($r['status'] ?? '')
    . ' enrolled=' . count($r['enrolled'] ?? [])
    . ' already=' . count($r['alreadyenrolled'] ?? [])
    . ' failed=' . count($r['failed'] ?? [])
    . ' userdeleted=' . (int)($r['userdeleted'] ?? -1) . PHP_EOL;

$studentenrol = $DB->get_records_sql(
    "SELECT c.id, c.shortname
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE ue.userid = ? AND c.id > 1
   ORDER BY c.id",
    [31]
);
echo 'STUDENT31_MOODLE_ENROL ' . implode(',', array_map(static fn($c) => $c->shortname . ':' . $c->id, $studentenrol)) . PHP_EOL;

$exec2 = academic_enrolment_helper::execute(31, 'admission_enrolment', [], $adminid);
echo 'ENROL_IDEMPOTENT status=' . ($exec2['result']['status'] ?? '')
    . ' enrolled=' . count($exec2['result']['enrolled'] ?? []) . PHP_EOL;

$lunits = lecturer_teaching_helper::list_course_units(33, 'active');
echo 'LECTURER33_UNITS count=' . count($lunits) . PHP_EOL;
foreach ($lunits as $lu) {
    echo '  LUNIT ' . ($lu['unitcode'] ?? $lu['code'] ?? '') . ' moodle=' . ($lu['moodlecourseid'] ?? 0) . PHP_EOL;
}
if (count($lunits) !== 1) {
    echo "WARN lecturer 33 listed " . count($lunits) . " units (expected assigned-only INT001)\n";
}

$moodleteach = lecturer_teaching_helper::list_moodle_teaching_courses(33);
echo 'LECTURER33_MOODLE count=' . count($moodleteach) . PHP_EOL;
foreach ($moodleteach as $mc) {
    echo '  MTEACH ' . $mc['shortname'] . ':' . $mc['courseid'] . ' role=' . $mc['role'] . PHP_EOL;
}

$beforeue = (int)$DB->count_records_sql(
    "SELECT COUNT(*) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE ue.userid = 33 AND e.courseid = 8"
);
course_unit_teacher_helper::assign([
    'userid' => 33,
    'courseunitid' => (int)$int001->id,
    'teachingrole' => 'co_lecturer',
    'status' => 'active',
], $adminid);
$afterue = (int)$DB->count_records_sql(
    "SELECT COUNT(*) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE ue.userid = 33 AND e.courseid = 8"
);
$users33 = (int)$DB->count_records('user', ['id' => 33, 'deleted' => 0]);
echo "LECTURER_ENROL_DUP before={$beforeue} after={$afterue} user_still_one={$users33}\n";

$shortnames = $DB->get_fieldset_select('course', 'shortname', 'id > 1');
$dupshorts = array_diff_assoc($shortnames, array_unique($shortnames));
echo 'DUP_MOODLE_SHORTNAMES ' . ($dupshorts ? implode(',', $dupshorts) : 'none') . PHP_EOL;

echo "PASS curriculum e2e flow\n";
