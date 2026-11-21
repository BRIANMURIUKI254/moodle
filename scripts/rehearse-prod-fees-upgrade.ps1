# Rehearse local_btiportal fees-gate upgrade on a production DB dump (scratch database only).
# Does NOT modify the live dev "moodle" database.
param(
    [string]$SqlPath = ""
)

$ErrorActionPreference = "Stop"
$moodleRoot = Split-Path -Parent $PSScriptRoot
$lmsRoot = Split-Path -Parent $moodleRoot
$backups = Join-Path $lmsRoot "backups"

if (-not $SqlPath) {
    $latest = Get-ChildItem -Path $backups -Filter "*.sql" -File |
        Where-Object { $_.Name -notmatch 'dev-pre-feesgate' } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if (-not $latest) {
        throw "No .sql backup found under $backups"
    }
    $SqlPath = $latest.FullName
}

$scratchDb = "moodle_fees_rehearsal"
Write-Host "Using SQL: $SqlPath"
Write-Host "Scratch database: $scratchDb"

docker exec moodle-db mariadb -uroot -prootpass123 -e "DROP DATABASE IF EXISTS ``$scratchDb``; CREATE DATABASE ``$scratchDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON ``$scratchDb``.* TO 'moodleuser'@'%'; FLUSH PRIVILEGES;"
Get-Content -Raw -Path $SqlPath | docker exec -i moodle-db mariadb -uroot -prootpass123 $scratchDb

docker exec -e "BTI_MOODLE_DBNAME=$scratchDb" moodle-app php /var/www/html/admin/cli/upgrade.php --non-interactive --allow-unstable

docker exec moodle-db mariadb -uroot -prootpass123 $scratchDb -e @"
SELECT COUNT(*) AS migration_fees FROM mdl_local_btiportal_student_fees WHERE source='migration';
SELECT COUNT(DISTINCT userid) AS profile_users FROM mdl_local_btiportal_student_profile;
SELECT COUNT(DISTINCT ra.userid) AS student_role_users
  FROM mdl_role_assignments ra
  JOIN mdl_role r ON r.id = ra.roleid AND r.shortname = 'student'
  JOIN mdl_user u ON u.id = ra.userid AND u.deleted = 0;
"@

Write-Host "Rehearsal complete (scratch DB $scratchDb left in place for inspection)."