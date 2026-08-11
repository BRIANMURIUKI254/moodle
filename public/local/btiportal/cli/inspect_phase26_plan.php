<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\student_delivery_plan_helper;
use local_btiportal\curriculum_delivery_helper;
use local_btiportal\academic_enrolment_helper;
use local_btiportal\student_academic_progression_helper;

$user = $DB->get_record('user', ['username' => 'portal_student', 'deleted' => 0], '*', MUST_EXIST);
$profile = $DB->get_record('local_btiportal_student_profile', ['userid' => $user->id], '*', MUST_EXIST);
cli_writeln("user id={$user->id} username={$user->username} email={$user->email}");
cli_writeln("placement curr={$profile->curriculumid} year={$profile->curriculumyearid} sem={$profile->curriculumsemesterid} status={$profile->academicstatus}");

$cy = $DB->get_record('local_btiportal_curriculum_year', ['id' => $profile->curriculumyearid]);
$cs = $DB->get_record('local_btiportal_curriculum_semester', ['id' => $profile->curriculumsemesterid]);
cli_writeln('yearnumber=' . ($cy->yearnumber ?? 'null') . ' semesternumber=' . ($cs->semesternumber ?? 'null'));

$units = $DB->get_records('local_btiportal_course_unit', ['curriculumid' => (int)$profile->curriculumid]);
foreach ($units as $u) {
    cli_writeln("unit {$u->id} {$u->code} y={$u->yearofstudy} s={$u->semestersequence} courseid={$u->courseid} status={$u->status}");
}

$delivery = curriculum_delivery_helper::get_curriculum_delivery((int)$profile->curriculumid);
cli_writeln('delivery_totals=' . json_encode($delivery['record']['totals'] ?? null));

$plan = student_delivery_plan_helper::get_plan((int)$user->id);
cli_writeln('plan_ok=' . (!empty($plan['ok']) ? '1' : '0'));
cli_writeln('plan_summary=' . json_encode($plan['plan']['summary'] ?? $plan['errors'] ?? null));
cli_writeln('plan_items=' . count($plan['plan']['items'] ?? []));

$preview = academic_enrolment_helper::preview((int)$user->id, 'semester_progression_enrolment');
cli_writeln('preview_ok=' . (!empty($preview['ok']) ? '1' : '0'));
cli_writeln('toenrol=' . count($preview['preview']['toenrol'] ?? []));
cli_writeln('already=' . count($preview['preview']['alreadyenrolled'] ?? []));
cli_writeln('skipped=' . count($preview['preview']['skipped'] ?? []));

// Promote preview
$prom = student_academic_progression_helper::preview_promotion((int)$user->id, []);
cli_writeln('promotion_preview_ok=' . (!empty($prom['ok']) ? '1' : '0'));
if (empty($prom['ok'])) {
    cli_writeln('promotion_error=' . ($prom['errors'][0]['message'] ?? ''));
}

$hist = $DB->get_records('local_btiportal_student_acad_hist', ['userid' => $user->id], 'id DESC', '*', 0, 5);
foreach ($hist as $h) {
    cli_writeln("hist {$h->id} action={$h->actiontype} fromY={$h->fromcurriculumyearid} toY={$h->tocurriculumyearid} fromS={$h->fromcurriculumsemesterid} toS={$h->tocurriculumsemesterid}");
}
