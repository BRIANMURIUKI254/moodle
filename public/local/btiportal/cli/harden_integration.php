<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\curriculum_unit_helper;
use local_btiportal\lecturer_teaching_helper;
use local_btiportal\student_enrolment_audit_helper;
use local_btiportal\academic_course_unit_helper;

global $DB;

$fail = 0;
function check($label, $ok, $detail = '') {
    global $fail;
    echo ($ok ? 'PASS' : 'FAIL') . " {$label}" . ($detail !== '' ? " | {$detail}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

echo "=== VERSIONS ===\n";
$installed = (string)$DB->get_field('config_plugins', 'value', [
    'plugin' => 'local_btiportal',
    'name' => 'version',
]);
$v = file_get_contents('/var/www/html/public/local/btiportal/version.php');
preg_match('/\$plugin->version\s*=\s*(\d+)/', $v, $m);
$runtime = $m[1] ?? '';
echo "installed={$installed} runtime_file={$runtime}\n";
check('source_runtime_version_match', $installed === $runtime && $runtime === '2026081304', "installed={$installed} runtime={$runtime}");

echo "\n=== CATALOGUE SAFETY ===\n";
$units = $DB->get_records('local_btiportal_course_unit', null, 'id ASC');
$bycode = [];
foreach ($units as $u) {
    $bycode[strtoupper((string)$u->code)] = $u;
    echo "UNIT id={$u->id} code={$u->code} moodle={$u->courseid} status={$u->status}\n";
}
foreach (['INT001' => 8, 'MAT101' => 9, 'COM101' => 10, 'CSC103' => 11] as $code => $moodle) {
    $u = $bycode[$code] ?? null;
    check("unit_{$code}_exists", $u !== null);
    if ($u) {
        check("unit_{$code}_maps_moodle_{$moodle}", (int)$u->courseid === $moodle, "courseid=" . (int)$u->courseid);
    }
}
$ele = $DB->get_record('course', ['id' => 7, 'shortname' => 'ELE001']);
check('moodle_ELE001_id7_preserved', !empty($ele));

echo "\n=== CURRICULUM INTEGRITY ===\n";
$c1 = curriculum_unit_helper::list_assignments(1);
$codes = array_map(static fn($u) => strtoupper((string)$u['code']), $c1);
sort($codes);
check('curriculum_1_four_units', count($c1) === 4, 'count=' . count($c1) . ' codes=' . implode(',', $codes));
check('curriculum_1_expected_codes', $codes === ['COM101', 'CSC103', 'INT001', 'MAT101'], implode(',', $codes));

$dup = curriculum_unit_helper::save_assignments(1, [
    ['courseunitid' => (int)$bycode['INT001']->id, 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 1],
    ['courseunitid' => (int)$bycode['INT001']->id, 'yearofstudy' => 1, 'semestersequence' => 1, 'sortorder' => 2],
], 2);
check('duplicate_unit_rejected', empty($dup['ok']), json_encode($dup['errors'] ?? []));

$intcurrs = $DB->get_records('local_btiportal_curriculum_unit', ['courseunitid' => (int)$bycode['INT001']->id]);
check('int001_shared_across_curricula', count($intcurrs) >= 2, 'curricula=' . count($intcurrs));

echo "\n=== STUDENT 31 ENROLMENT AUDIT ===\n";
$audit = student_enrolment_audit_helper::audit(31);
check('student_31_audit_ok', !empty($audit['ok']));
$summary = $audit['record']['summary'] ?? [];
echo 'summary=' . json_encode($summary) . PHP_EOL;
$legacyele = false;
$inplan = [];
foreach ($audit['record']['items'] ?? [] as $item) {
    echo "  {$item['code']} moodle={$item['moodlecourseid']} {$item['classification']}\n";
    if ((int)$item['moodlecourseid'] === 7 && ($item['classification'] ?? '') === 'legacy') {
        $legacyele = true;
    }
    if (($item['classification'] ?? '') === 'in_plan') {
        $inplan[] = strtoupper((string)$item['code']);
    }
}
sort($inplan);
check('student_31_in_plan_four', $inplan === ['COM101', 'CSC103', 'INT001', 'MAT101'], implode(',', $inplan));
check('student_31_ele001_legacy_not_auto_removed', $legacyele);
$noconfirm = student_enrolment_audit_helper::remove_legacy(31, 7, false);
check('legacy_remove_requires_confirm', empty($noconfirm['ok']));
$still = $DB->record_exists_sql(
    "SELECT 1 FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE ue.userid = 31 AND e.courseid = 7"
);
check('ele001_enrolment_still_present', $still);

echo "\n=== LECTURER 33 RECONCILE ===\n";
$before = lecturer_teaching_helper::list_course_units(33, 'active');
foreach ($before as $row) {
    echo "BEFORE {$row['unitcode']} moodle={$row['moodlecourseid']} {$row['moodleenrolmentstatus']} {$row['moodleenrolmentlabel']}\n";
}
$recon = lecturer_teaching_helper::reconcile_moodle_enrolments(33);
check('lecturer_33_reconcile_ok', !empty($recon['ok']), json_encode($recon['record'] ?? []));
$after = lecturer_teaching_helper::list_course_units(33, 'active');
$intconnected = false;
foreach ($after as $row) {
    echo "AFTER {$row['unitcode']} moodle={$row['moodlecourseid']} {$row['moodleenrolmentstatus']}\n";
    if (strtoupper((string)$row['unitcode']) === 'INT001' && ($row['moodleenrolmentstatus'] ?? '') === 'connected') {
        $intconnected = true;
    }
}
check('lecturer_33_int001_connected', $intconnected);

echo "\n=== DELETE SAFETY ===\n";
$matbefore = $DB->get_record('local_btiportal_course_unit', ['code' => 'MAT101']);
academic_course_unit_helper::delete_for_course(9);
$matafter = $DB->get_record('local_btiportal_course_unit', ['code' => 'MAT101']);
check('delete_for_course_does_not_drop_row', !empty($matafter));
check('delete_for_course_unlinks_only', !empty($matafter) && (int)$matafter->courseid === 0, 'courseid=' . (int)($matafter->courseid ?? -1));
if ($matbefore && $matafter) {
    $matafter->courseid = (int)$matbefore->courseid;
    $matafter->sectionid = (int)$matbefore->sectionid;
    if (property_exists($matbefore, 'moodleverifiedat')) {
        $matafter->moodleverifiedat = (int)$matbefore->moodleverifiedat;
    }
    $matafter->timemodified = time();
    $DB->update_record('local_btiportal_course_unit', $matafter);
    check('mat101_mapping_restored', (int)$DB->get_field('local_btiportal_course_unit', 'courseid', ['code' => 'MAT101']) === 9);
}

echo "\n=== RESULT fail={$fail} ===\n";
exit($fail > 0 ? 1 : 0);
