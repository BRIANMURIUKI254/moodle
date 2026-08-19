<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

use local_btiportal\student_delivery_plan_helper;
use local_btiportal\student_unit_pathway_helper;
use local_btiportal\student_profile_helper;
use local_btiportal\curriculum_delivery_helper;

$userid = (int)($argv[1] ?? 34);
$profile = student_profile_helper::get_by_userid($userid);
echo json_encode($profile, JSON_PRETTY_PRINT) . PHP_EOL;
$plan = student_delivery_plan_helper::get_plan($userid);
echo 'PLAN:' . PHP_EOL . json_encode($plan, JSON_PRETTY_PRINT) . PHP_EOL;
$path = student_unit_pathway_helper::get_pathway($userid);
echo 'PATHWAY:' . PHP_EOL . json_encode($path, JSON_PRETTY_PRINT) . PHP_EOL;

if ($profile && (int)$profile['curriculumid'] > 0) {
    $delivery = curriculum_delivery_helper::get_for_placement(
        (int)$profile['curriculumid'],
        (int)$profile['curriculumyearid'],
        (int)$profile['curriculumsemesterid']
    );
    echo 'CURRICULUM_DELIVERY:' . PHP_EOL . json_encode($delivery, JSON_PRETTY_PRINT) . PHP_EOL;
}
