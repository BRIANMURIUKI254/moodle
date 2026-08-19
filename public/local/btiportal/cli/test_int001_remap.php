<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\moodle_mapping_diagnostics_helper;

global $DB;

$code = 'INT001';
$unit = $DB->get_record('local_btiportal_course_unit', ['code' => $code]);
if (!$unit) {
    echo "FAIL unit_missing code={$code}\n";
    exit(1);
}

$admin = get_admin();
$userid = $admin ? (int)$admin->id : 2;

$coursesbefore = $DB->get_records_sql(
    "SELECT id, shortname, idnumber FROM {course} WHERE id > 1 ORDER BY id"
);
$beforecount = count($coursesbefore);
$ele001 = $DB->get_record('course', ['id' => 7], 'id,shortname,fullname');
$int001moodle = $DB->get_record('course', ['shortname' => $code], 'id,shortname,fullname,idnumber');

echo "current_mapping unit={$unit->code} id={$unit->id} courseid={$unit->courseid}\n";
echo "current_moodle_7 exists=" . ($ele001 ? '1' : '0')
    . " shortname=" . ($ele001->shortname ?? '') . "\n";
echo "matching_moodle exists=" . ($int001moodle ? '1' : '0')
    . " id=" . ($int001moodle->id ?? 0)
    . " shortname=" . ($int001moodle->shortname ?? '') . "\n";
echo "moodle_course_count_before={$beforecount}\n";

$preview = moodle_mapping_diagnostics_helper::preview_unit((int)$unit->id);
$record = $preview['record'] ?? [];
echo "preview_ok=" . (!empty($preview['ok']) ? '1' : '0') . "\n";
echo "proposed_mapping unit={$record['unitcode']} -> {$record['matchshortname']} (ID {$record['matchcourseid']})\n";
echo "matchcount={$record['matchcount']} wouldremap={$record['wouldremap']} wouldcreate={$record['wouldcreate']} wouldreuse={$record['wouldreuse']}\n";
echo "needsselection={$record['needsselection']}\n";

$rejected = moodle_mapping_diagnostics_helper::sync_unit((int)$unit->id, $userid, false, (int)$record['matchcourseid']);
echo "sync_without_confirm ok=" . (!empty($rejected['ok']) ? '1' : '0') . "\n";
echo "sync_without_confirm_error=" . ($rejected['errors'][0]['message'] ?? '') . "\n";

$afterreject = $DB->get_record('local_btiportal_course_unit', ['id' => $unit->id], 'id,code,courseid');
echo "mapping_after_reject courseid={$afterreject->courseid}\n";

$confirm = getenv('CONFIRM_REMAP') === '1';
if (!$confirm) {
    echo "remapping_performed=0 (pass CONFIRM_REMAP=1 to apply)\n";
    echo "moodle_course_creation_occurred=0\n";
    echo "duplicate_courses_detected=" . ((int)$record['matchcount'] > 1 ? '1' : '0') . "\n";
    exit(!empty($rejected['ok']) || (int)$afterreject->courseid !== (int)$unit->courseid ? 1 : 0);
}

$applied = moodle_mapping_diagnostics_helper::sync_unit(
    (int)$unit->id,
    $userid,
    true,
    (int)$record['matchcourseid']
);
echo "sync_with_confirm ok=" . (!empty($applied['ok']) ? '1' : '0') . "\n";
echo "sync_with_confirm_error=" . ($applied['errors'][0]['message'] ?? '') . "\n";
$after = $DB->get_record('local_btiportal_course_unit', ['id' => $unit->id], 'id,code,courseid,sectionid');
$verifiedat = 0;
if ($DB->get_manager()->field_exists('local_btiportal_course_unit', 'moodleverifiedat')) {
    $verifiedat = (int)$DB->get_field('local_btiportal_course_unit', 'moodleverifiedat', ['id' => $unit->id]);
}
echo "mapping_after_confirm courseid={$after->courseid} sectionid={$after->sectionid} verifiedat={$verifiedat}\n";

$ele001after = $DB->record_exists('course', ['id' => 7]);
$int001after = $DB->get_record('course', ['shortname' => $code], 'id,shortname');
$coursesafter = $DB->count_records_select('course', 'id > 1');
$int001dupes = $DB->count_records('course', ['shortname' => $code]);

$diag = moodle_mapping_diagnostics_helper::diagnose();
$intissue = null;
foreach (($diag['record']['issues'] ?? []) as $issue) {
    if (strcasecmp((string)$issue['code'], $code) === 0) {
        $intissue = $issue;
        break;
    }
}

echo "moodle_course_7_still_exists=" . ($ele001after ? '1' : '0') . "\n";
echo "moodle_int001_id=" . ((int)($int001after->id ?? 0)) . "\n";
echo "moodle_course_count_after={$coursesafter}\n";
echo "int001_shortname_course_count={$int001dupes}\n";
echo "moodle_course_creation_occurred=" . ($coursesafter > $beforecount ? '1' : '0') . "\n";
echo "duplicate_courses_detected=" . ($int001dupes > 1 || (int)$record['matchcount'] > 1 ? '1' : '0') . "\n";
echo "remapping_performed=" . ((int)$after->courseid === (int)$record['matchcourseid'] ? '1' : '0') . "\n";
echo "diagnostics_int001_kind=" . ($intissue['kind'] ?? 'connected_or_absent') . "\n";
echo "diagnostics_int001_status=" . ($intissue['mappingstatus'] ?? ($applied['record']['mappingstatus'] ?? '')) . "\n";

$ok = !empty($applied['ok'])
    && (int)$after->courseid === (int)$record['matchcourseid']
    && $ele001after
    && $coursesafter === $beforecount
    && $int001dupes === 1
    && $intissue === null;
exit($ok ? 0 : 1);
