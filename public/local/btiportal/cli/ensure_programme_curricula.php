<?php
/**
 * Ensure every programme has an active curriculum with years/semesters for admission.
 *
 * Usage: php public/local/btiportal/cli/ensure_programme_curricula.php
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\curriculum_helper;
use local_btiportal\curriculum_structure_helper;

cli_heading('Ensure programme curricula for admission');

if (!$DB->get_manager()->table_exists('local_btiportal_programme')) {
    cli_error('Programme table missing');
}

$programmes = $DB->get_records('local_btiportal_programme', null, 'id ASC');
$userid = 2; // admin-ish; structure helpers accept usermodified.

foreach ($programmes as $programme) {
    $pid = (int)$programme->id;
    $code = trim((string)$programme->code);
    $name = trim((string)$programme->name);
    $award = strtolower(trim((string)$programme->awardtype));
    $duration = (float)$programme->duration;
    $durationunit = strtolower(trim((string)$programme->durationunit));

    $yearsneeded = 1;
    if ($durationunit === 'years' && $duration > 0) {
        $yearsneeded = max(1, (int)ceil($duration));
    } else if (in_array($award, ['diploma', 'bachelor', 'master', 'doctorate'], true)) {
        $map = ['diploma' => 2, 'bachelor' => 4, 'master' => 2, 'doctorate' => 3];
        $yearsneeded = $map[$award] ?? 1;
    }

    $existing = $DB->get_records('local_btiportal_curriculum', ['programmeid' => $pid], 'id ASC');
    $active = null;
    foreach ($existing as $c) {
        if ((string)$c->status === 'active') {
            $active = $c;
            break;
        }
    }
    if (!$active && $existing) {
        $active = reset($existing);
        if ((string)$active->status !== 'active') {
            $active->status = 'active';
            $active->timemodified = time();
            $DB->update_record('local_btiportal_curriculum', $active);
            cli_writeln("Activated curriculum #{$active->id} for {$code}");
        }
    }

    if (!$active) {
        $result = curriculum_helper::create([
            'programmeid' => $pid,
            'code' => $code . '-CUR',
            'name' => $name . ' Curriculum',
            'version' => date('Y') . '.1',
            'description' => 'Auto-created for student admission placement',
            'status' => 'active',
            'effectivefrom' => time(),
            'effectiveto' => 0,
        ]);
        if (empty($result['ok'])) {
            cli_writeln("FAILED create curriculum for {$code}: " . json_encode($result['errors'] ?? []));
            continue;
        }
        $active = (object)$result['record'];
        // get returns array; reload.
        $active = $DB->get_record('local_btiportal_curriculum', ['id' => (int)$result['record']['id']], '*', MUST_EXIST);
        cli_writeln("Created curriculum #{$active->id} for {$code} ({$name})");
    } else {
        cli_writeln("Using curriculum #{$active->id} for {$code}");
    }

    $cid = (int)$active->id;
    for ($yn = 1; $yn <= $yearsneeded; $yn++) {
        $year = $DB->get_record('local_btiportal_curriculum_year', [
            'curriculumid' => $cid,
            'yearnumber' => $yn,
        ]);
        if (!$year) {
            $yres = curriculum_structure_helper::create_year([
                'curriculumid' => $cid,
                'yearnumber' => $yn,
                'name' => 'Year ' . $yn,
                'description' => '',
                'status' => 'active',
            ], $userid);
            if (empty($yres['ok'])) {
                cli_writeln("  FAILED year {$yn}: " . json_encode($yres['errors'] ?? []));
                continue;
            }
            $yearid = (int)$yres['record']['id'];
            cli_writeln("  Created Year {$yn} (#{$yearid})");
        } else {
            $yearid = (int)$year->id;
            if ((string)$year->status !== 'active') {
                $year->status = 'active';
                $year->timemodified = time();
                $DB->update_record('local_btiportal_curriculum_year', $year);
            }
        }

        $sem = $DB->get_record('local_btiportal_curriculum_semester', [
            'curriculumyearid' => $yearid,
            'semesternumber' => 1,
        ]);
        if (!$sem) {
            $sres = curriculum_structure_helper::create_semester([
                'curriculumyearid' => $yearid,
                'semesternumber' => 1,
                'name' => 'Semester 1',
                'creditrequirement' => 0,
                'status' => 'active',
            ], $userid);
            if (empty($sres['ok'])) {
                cli_writeln("  FAILED semester 1 on year {$yn}: " . json_encode($sres['errors'] ?? []));
            } else {
                cli_writeln("  Created Semester 1 on Year {$yn}");
            }
        }

        // Diploma/degree often have semester 2.
        if ($yearsneeded >= 2 || in_array($award, ['diploma', 'bachelor', 'master', 'doctorate'], true)) {
            $sem2 = $DB->get_record('local_btiportal_curriculum_semester', [
                'curriculumyearid' => $yearid,
                'semesternumber' => 2,
            ]);
            if (!$sem2) {
                $sres2 = curriculum_structure_helper::create_semester([
                    'curriculumyearid' => $yearid,
                    'semesternumber' => 2,
                    'name' => 'Semester 2',
                    'creditrequirement' => 0,
                    'status' => 'active',
                ], $userid);
                if (!empty($sres2['ok'])) {
                    cli_writeln("  Created Semester 2 on Year {$yn}");
                }
            }
        }
    }
}

cli_writeln('Done. Admission Curriculum / Year of study should now populate per programme.');
