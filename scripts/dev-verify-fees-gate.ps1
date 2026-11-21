# Dev: 23/23 feesunpaid matrix via host curl (Moodle http://localhost:8081)
$ErrorActionPreference = "Stop"
$base = "http://localhost:8081"
$pass = "Test@Dev2026!"
docker exec moodle-app php -r "define('CLI_SCRIPT',true);require '/var/www/html/public/config.php';set_config('feesgate_enabled',1,'local_btiportal');" | Out-Null
$tu = (curl.exe -s -d "username=fee.unpaid&password=$pass&service=bti_portal_api" "$base/login/token.php" | ConvertFrom-Json).token
$courseid = [int](docker exec moodle-app php -r "define('CLI_SCRIPT',true);require '/var/www/html/public/config.php';echo (int)`$DB->get_field('course','id',['shortname'=>'BAC001']);")
if ($courseid -lt 1) { $courseid = 2 }

function Invoke-BtiWs {
    param([string]$Token, [string]$Function, [hashtable]$Extra = @{})
    $q = "wstoken=$Token&wsfunction=$Function&moodlewsrestformat=json"
    foreach ($k in $Extra.Keys) {
        $v = $Extra[$k]
        if ($v -is [hashtable]) {
            foreach ($ik in $v.Keys) {
                $q += "&$k%5B$ik%5D=$([uri]::EscapeDataString([string]$v[$ik]))"
            }
        } else {
            $q += "&$k=$([uri]::EscapeDataString([string]$v))"
        }
    }
    return curl.exe -s "$base/webservice/rest/server.php?$q"
}

$extra = @{
    'local_btiportal_get_my_course_access' = @{ courseid = $courseid }
    'local_btiportal_get_my_final_exam_attempt_status' = @{ quizid = 1 }
    'local_btiportal_get_assessment_access' = @{ cmid = 1 }
    'local_btiportal_launch_quiz_attempt' = @{ quizid = 1; courseid = $courseid }
    'local_btiportal_ensure_certificate' = @{ courseid = $courseid }
    'local_btiportal_save_my_student_elective_selections' = @{
        electivegroupid = 1
        academicyearid = 1
        semesterid = 1
        courseunitids = @{ 0 = 1 }
    }
}

$gated = @(
    'local_btiportal_get_my_student_workspace','local_btiportal_get_my_student_academic_profile',
    'local_btiportal_get_my_student_delivery_plan','local_btiportal_get_my_student_academic_history',
    'local_btiportal_get_my_progression_eligibility','local_btiportal_list_my_elective_groups',
    'local_btiportal_list_my_student_elective_selections','local_btiportal_save_my_student_elective_selections',
    'local_btiportal_list_my_student_timetable_entries','local_btiportal_ensure_my_pathway_enrolments',
    'local_btiportal_get_my_course_access','local_btiportal_get_my_final_exam_attempt_status',
    'local_btiportal_get_my_enrolled_programme_courses','local_btiportal_get_my_gradebook',
    'local_btiportal_get_assessments','local_btiportal_get_assessment_access',
    'local_btiportal_launch_quiz_attempt','local_btiportal_get_recordings','local_btiportal_get_resources',
    'local_btiportal_get_events','local_btiportal_get_announcements',
    'local_btiportal_get_certificates','local_btiportal_ensure_certificate'
)

$passCount = 0
foreach ($fn in $gated) {
    $h = @{}
    if ($extra.ContainsKey($fn)) {
        $h = $extra[$fn]
    }
    $body = Invoke-BtiWs -Token $tu -Function $fn -Extra $h
    $ok = $body -match 'feesunpaid'
    if ($ok) { $passCount++ }
    $label = if ($ok) { 'PASS' } else { 'FAIL' }
    $snippet = if ($body.Length -gt 100) { $body.Substring(0, 100) } else { $body }
    Write-Output "$label`t$fn`t$snippet"
}
Write-Output "Summary $passCount/23"
docker exec moodle-app php /var/www/html/public/local/btiportal/cli/configure_admissions_fees_callback.php --show
docker exec moodle-app php -r "define('CLI_SCRIPT',true);require '/var/www/html/public/config.php';set_config('feesgate_enabled',0,'local_btiportal');"