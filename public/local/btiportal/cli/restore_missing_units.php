<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

use local_btiportal\moodle_catalogue_sync_helper;

global $DB;

$template = $DB->get_record('local_btiportal_course_unit', ['id' => 1], '*', MUST_EXIST);
echo "template keys=" . implode(',', array_keys((array)$template)) . PHP_EOL;

$wanted = [
    2 => ['code' => 'MAT101', 'name' => 'Mathematics I', 'courseid' => 9, 'curriculumid' => 2, 'programmeid' => 1, 'yearofstudy' => 1, 'semestersequence' => 1],
    3 => ['code' => 'COM101', 'name' => 'Communication Skills', 'courseid' => 10, 'curriculumid' => 2, 'programmeid' => 1, 'yearofstudy' => 1, 'semestersequence' => 1],
    4 => ['code' => 'CSC103', 'name' => 'Data Structures', 'courseid' => 11, 'curriculumid' => 2, 'programmeid' => 1, 'yearofstudy' => 1, 'semestersequence' => 2],
];

foreach ($wanted as $id => $spec) {
    $existing = $DB->get_record('local_btiportal_course_unit', ['id' => $id]);
    $bycode = $DB->get_record_sql(
        "SELECT * FROM {local_btiportal_course_unit} WHERE UPPER(code) = UPPER(:code)",
        ['code' => $spec['code']]
    );
    if ($existing || $bycode) {
        echo "SKIP {$spec['code']} already id=" . ($existing->id ?? $bycode->id) . " moodle=" . ($existing->courseid ?? $bycode->courseid) . PHP_EOL;
        continue;
    }
    $course = $DB->get_record('course', ['id' => $spec['courseid']]);
    if (!$course) {
        echo "FAIL moodle course {$spec['courseid']} missing for {$spec['code']}\n";
        exit(1);
    }
    $synced = moodle_catalogue_sync_helper::ensure_unit_section((int)$spec['courseid'], $spec['name']);
    $now = time();
    $row = (object)[
        'id' => $id,
        'code' => $spec['code'],
        'name' => $spec['name'],
        'description' => $spec['name'],
        'programmeid' => $spec['programmeid'],
        'curriculumid' => $spec['curriculumid'],
        'yearofstudy' => $spec['yearofstudy'],
        'semestersequence' => $spec['semestersequence'],
        'courseid' => $spec['courseid'],
        'sectionid' => (int)$synced['sectionid'],
        'credithours' => 3,
        'unittype' => 'core',
        'status' => 'active',
        'isrequired' => 1,
        'createdby' => 2,
        'modifiedby' => 2,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    if (property_exists($template, 'moodleverifiedat')) {
        $row->moodleverifiedat = 0;
    }
    if (property_exists($template, 'imageurl')) {
        $row->imageurl = '';
    }
    $DB->import_record('local_btiportal_course_unit', $row);
    $saved = $DB->get_record('local_btiportal_course_unit', ['id' => $id]);
    echo "RESTORED id={$saved->id} code={$saved->code} moodle={$saved->courseid} section={$saved->sectionid}\n";
}

echo "units=" . $DB->count_records('local_btiportal_course_unit') . PHP_EOL;
echo "moodle=" . $DB->count_records_select('course', 'id > 1') . PHP_EOL;
foreach ($DB->get_records('local_btiportal_course_unit', null, 'id ASC') as $u) {
    echo "UNIT id={$u->id} {$u->code} moodle={$u->courseid}\n";
}
