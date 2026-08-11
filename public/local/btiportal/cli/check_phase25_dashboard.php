<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
$d = \local_btiportal\academic_dashboard_helper::get_admin_academic_dashboard();
foreach ([
    'activestudents', 'deferredstudents', 'withdrawnstudents',
    'readmissionsperiod', 'programmetransfersperiod',
    'activecourseunitassignments', 'lecturersmulticourseunits', 'studentprofiles',
] as $k) {
    echo $k . '=' . ($d['counts'][$k] ?? 'MISSING') . PHP_EOL;
}
