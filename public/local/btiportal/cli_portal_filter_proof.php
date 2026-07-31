<?php
/**
 * Replicate portal grading-queue filter exactly; print PASS/FAIL.
 * Run: docker exec moodle-app php /var/www/html/public/local/btiportal/cli_portal_filter_proof.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

function queue_for(string $username): array {
    global $DB;
    $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
    $maps = $DB->get_records('local_btiportal_unit', ['teacheruserid' => $user->id]);
    $sectionSet = [];
    foreach ($maps as $m) {
        $sectionSet[(int)$m->courseid . ':' . (int)$m->sectionid] = true;
    }

    $out = [];
    foreach ($maps as $m) {
        $courseid = (int)$m->courseid;
        $sectionid = (int)$m->sectionid;
        $assigns = $DB->get_records_sql(
            "SELECT a.id, a.name, cm.id AS cmid
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id
               JOIN {modules} md ON md.id = cm.module AND md.name = 'assign'
              WHERE a.course = ? AND cm.section = ?",
            [$courseid, $sectionid]
        );
        foreach ($assigns as $a) {
            $key = $courseid . ':' . $sectionid;
            if (empty($sectionSet[$key])) {
                continue;
            }
            $subs = $DB->get_records('assign_submission', [
                'assignment' => $a->id,
                'status' => 'submitted',
            ]);
            foreach ($subs as $sub) {
                $su = $DB->get_record('user', ['id' => $sub->userid], 'username');
                $out[] = [
                    'assignid' => (int)$a->id,
                    'assign' => $a->name,
                    'sectionid' => $sectionid,
                    'student' => $su->username,
                ];
            }
        }
    }
    return $out;
}

foreach (['portal_instructor', 'portal_instructor2'] as $uname) {
    $rows = queue_for($uname);
    echo "=== {$uname} portal-scoped queue (" . count($rows) . ") ===\n";
    foreach ($rows as $r) {
        echo "  assign={$r['assignid']} ({$r['assign']}) section={$r['sectionid']} student={$r['student']}\n";
    }
}

$t1 = array_column(queue_for('portal_instructor'), 'assignid');
$t2 = array_column(queue_for('portal_instructor2'), 'assignid');
$overlap = array_intersect($t1, $t2);
echo "overlap=" . (empty($overlap) ? 'NONE' : implode(',', $overlap)) . "\n";
echo "T1 A=" . (in_array(1, $t1, true) ? 'yes' : 'no') . " B=" . (in_array(2, $t1, true) ? 'yes' : 'no') . "\n";
echo "T2 A=" . (in_array(1, $t2, true) ? 'yes' : 'no') . " B=" . (in_array(2, $t2, true) ? 'yes' : 'no') . "\n";
$pass = in_array(1, $t1, true) && !in_array(2, $t1, true)
    && !in_array(1, $t2, true) && in_array(2, $t2, true)
    && empty($overlap);
echo $pass ? "PORTAL_FILTER_ISOLATION_PASS\n" : "PORTAL_FILTER_ISOLATION_FAIL\n";
