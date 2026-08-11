<?php
/**
 * Create Moodle course container(s) for Course Unit mapping after a wipe.
 *
 * Usage: php public/local/btiportal/cli/seed_moodle_course_containers.php
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

cli_heading('Seed Moodle course containers');

$categoryid = (int)$DB->get_field_sql(
    "SELECT id FROM {course_categories} WHERE parent = 0 ORDER BY sortorder ASC, id ASC",
    [],
    IGNORE_MULTIPLE
);
if ($categoryid <= 0) {
    $category = core_course_category::create([
        'name' => 'Miscellaneous',
        'parent' => 0,
        'visible' => 1,
    ]);
    $categoryid = (int)$category->id;
    cli_writeln("Created category #{$categoryid}");
} else {
    cli_writeln("Using category #{$categoryid}");
}

$desired = [];
if ($DB->get_manager()->table_exists('local_btiportal_programme')) {
    foreach ($DB->get_records('local_btiportal_programme', null, 'id ASC') as $p) {
        $code = trim((string)$p->code);
        $name = trim((string)$p->name);
        if ($code === '' || $name === '') {
            continue;
        }
        $desired[$code] = [
            'shortname' => $code,
            'fullname' => $name,
            'summary' => 'Moodle course container for ' . $name,
        ];
    }
}
if (!$desired) {
    $desired['ELE001'] = [
        'shortname' => 'ELE001',
        'fullname' => 'Electrical Engineering',
        'summary' => 'Moodle course container for Electrical Engineering',
    ];
}

foreach ($desired as $spec) {
    $existing = $DB->get_record('course', ['shortname' => $spec['shortname']]);
    if ($existing) {
        cli_writeln("Already exists #{$existing->id}: {$existing->shortname} — {$existing->fullname}");
        continue;
    }

    $created = create_course((object)[
        'fullname' => $spec['fullname'],
        'shortname' => $spec['shortname'],
        'category' => $categoryid,
        'summary' => $spec['summary'],
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'numsections' => 0,
        'visible' => 1,
        'enablecompletion' => 1,
    ]);
    cli_writeln("Created #{$created->id}: {$created->shortname} — {$created->fullname}");
}

$remaining = $DB->get_records_select('course', 'id > ?', [SITEID], 'id ASC', 'id,shortname,fullname');
cli_writeln('Visible Moodle courses now: ' . count($remaining));
foreach ($remaining as $c) {
    cli_writeln("  #{$c->id} {$c->shortname} — {$c->fullname}");
}

purge_all_caches();
cli_writeln('Done. Refresh the Course Unit form — Moodle course should list these.');
