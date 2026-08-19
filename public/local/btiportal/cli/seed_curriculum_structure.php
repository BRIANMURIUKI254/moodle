<?php
/**
 * Ensure every curriculum has Year 1..N and Semester 1..2 structure
 * so admission "Year of study" can auto-fill.
 *
 * Usage: php public/local/btiportal/cli/seed_curriculum_structure.php
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\curriculum_structure_helper;

cli_heading('Seed curriculum years / semesters');

if (!$DB->get_manager()->table_exists('local_btiportal_curriculum')) {
    cli_error('Curriculum table missing');
}

$curricula = $DB->get_records('local_btiportal_curriculum', null, 'id ASC');
if (!$curricula) {
    cli_writeln('No curricula found.');
    exit(0);
}

foreach ($curricula as $curriculum) {
    $curriculumid = (int)$curriculum->id;
    $label = trim((string)$curriculum->code . ' ' . (string)$curriculum->name . ' (' . (string)$curriculum->version . ')');
    cli_writeln("Curriculum #{$curriculumid}: {$label}");

    $yearcount = 2;
    $programmeid = (int)$curriculum->programmeid;
    if ($programmeid > 0 && $DB->get_manager()->table_exists('local_btiportal_programme')) {
        $programme = $DB->get_record('local_btiportal_programme', ['id' => $programmeid]);
        if ($programme) {
            $award = strtolower((string)$programme->awardtype);
            $duration = (float)$programme->duration;
            $unit = strtolower((string)($programme->durationunit ?: 'years'));
            if ($duration > 0 && $unit === 'years') {
                $yearcount = max(1, min(8, (int)ceil($duration)));
            } else if ($award === 'certificate') {
                $yearcount = 1;
            } else if ($award === 'diploma' || $award === 'master') {
                $yearcount = 2;
            } else if ($award === 'bachelor') {
                $yearcount = 4;
            } else if ($award === 'doctorate') {
                $yearcount = 3;
            }
        }
    }

    for ($yn = 1; $yn <= $yearcount; $yn++) {
        $existing = $DB->get_record('local_btiportal_curriculum_year', [
            'curriculumid' => $curriculumid,
            'yearnumber' => $yn,
        ]);
        if ($existing) {
            $yearid = (int)$existing->id;
            cli_writeln("  Year {$yn} already exists (#{$yearid})");
        } else {
            $created = curriculum_structure_helper::create_year([
                'curriculumid' => $curriculumid,
                'yearnumber' => $yn,
                'name' => 'Year ' . $yn,
                'status' => 'active',
            ], 0);
            if (empty($created['ok'])) {
                $msg = $created['errors'][0]['message'] ?? 'create_year failed';
                cli_writeln("  FAILED Year {$yn}: {$msg}");
                continue;
            }
            $yearid = (int)$created['record']['id'];
            cli_writeln("  Created Year {$yn} (#{$yearid})");
        }

        for ($sn = 1; $sn <= 2; $sn++) {
            $sem = $DB->get_record('local_btiportal_curriculum_semester', [
                'curriculumyearid' => $yearid,
                'semesternumber' => $sn,
            ]);
            if ($sem) {
                cli_writeln("    Semester {$sn} already exists (#{$sem->id})");
                continue;
            }
            $semcreated = curriculum_structure_helper::create_semester([
                'curriculumyearid' => $yearid,
                'semesternumber' => $sn,
                'name' => 'Semester ' . $sn,
                'status' => 'active',
            ], 0);
            if (empty($semcreated['ok'])) {
                $msg = $semcreated['errors'][0]['message'] ?? 'create_semester failed';
                cli_writeln("    FAILED Semester {$sn}: {$msg}");
                continue;
            }
            cli_writeln("    Created Semester {$sn} (#{$semcreated['record']['id']})");
        }
    }
}

purge_all_caches();
cli_writeln('Done. Re-open Student Admission — Year of study should auto-fill Year 1.');
