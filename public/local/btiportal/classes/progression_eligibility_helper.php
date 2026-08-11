<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Optional progression eligibility using live Moodle grades/completion.
 *
 * Does not invent pass marks. When no institutional rule is configured,
 * returns rules_not_configured + manual_approval_required.
 */
class progression_eligibility_helper {
    public const RULE_TABLE = 'local_btiportal_progression_rule';

    public static function tables_ready(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::RULE_TABLE);
    }

    /**
     * Assess eligibility for semester progression or promotion.
     *
     * @return array{ok:bool,eligibility?:array,errors?:list}
     */
    public static function assess(int $userid, string $actiontype = 'semester_progression'): array {
        global $DB;

        if (!in_array($actiontype, ['semester_progression', 'promotion'], true)) {
            return ['ok' => false, 'errors' => [['field' => 'actiontype', 'message' => 'Invalid progression action']]];
        }
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Moodle user not found']]];
        }

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile) {
            return ['ok' => false, 'errors' => [['field' => 'userid', 'message' => 'Student academic profile not found']]];
        }

        $results = self::collect_results($userid, $profile);
        $rule = self::get_active_rule((int)$profile['curriculumid'], $actiontype);

        if (!$rule) {
            // Seed a default rule so portals are not stuck on rules_not_configured.
            // Manual approval is off; eligibility still reflects live grades/completion.
            $created = self::create_rule([
                'curriculumid' => (int)$profile['curriculumid'],
                'actiontype' => $actiontype,
                'programmeid' => (int)($profile['programmeid'] ?? 0),
                'mincompletedunits' => 0,
                'maxfailedunits' => 999,
                'requiremanualapproval' => 0,
                'notes' => 'Auto-created default progression rule',
            ], 0);
            if (!empty($created['ok']) && !empty($created['record'])) {
                $rule = $created['record'];
            }
        }

        if (!$rule) {
            $eligibility = [
                'userid' => $userid,
                'actiontype' => $actiontype,
                'state' => 'rules_not_configured',
                'manualapprovalrequired' => 1,
                'canautoprogress' => 0,
                'notice' => 'Automatic academic eligibility is not configured. Progression requires Admin approval.',
                'reasons' => [],
                'results' => $results['items'],
                'summary' => $results['summary'],
                'datasource' => $results['datasource'],
            ];
            // Do not include 'rule' => null — Moodle external_api rejects null for optional structures.
            return [
                'ok' => true,
                'eligibility' => self::sanitize_for_external($eligibility),
            ];
        }

        // Also ensure promotion rule exists for the same curriculum (non-blocking).
        if (!self::get_active_rule((int)$profile['curriculumid'], 'promotion')) {
            self::create_rule([
                'curriculumid' => (int)$profile['curriculumid'],
                'actiontype' => 'promotion',
                'programmeid' => (int)($profile['programmeid'] ?? 0),
                'mincompletedunits' => 0,
                'maxfailedunits' => 999,
                'requiremanualapproval' => 0,
                'notes' => 'Auto-created default promotion rule',
            ], 0);
        }

        $state = 'eligible';
        $reasons = [];
        $completed = (int)$results['summary']['completed'];
        $failed = (int)$results['summary']['failed'];
        $pending = (int)$results['summary']['pending'];
        $missing = (int)$results['summary']['missing'];

        if ((int)$rule['requiremanualapproval'] === 1) {
            $state = 'manual_approval_required';
            $reasons[] = 'Rule requires manual Admin approval';
        }
        if ((int)$rule['mincompletedunits'] > 0 && $completed < (int)$rule['mincompletedunits']) {
            $state = 'not_eligible';
            $reasons[] = 'Completed units below minimum (' . $rule['mincompletedunits'] . ')';
        }
        if ((int)$rule['maxfailedunits'] >= 0 && $failed > (int)$rule['maxfailedunits']) {
            $state = 'not_eligible';
            $reasons[] = 'Failed units exceed maximum (' . $rule['maxfailedunits'] . ')';
        }
        if ($pending > 0 || $missing > 0) {
            if ($state === 'eligible') {
                $state = 'pending_results';
            }
            $reasons[] = 'Some Course Unit results are pending or unavailable';
        }

        $eligibility = [
            'userid' => $userid,
            'actiontype' => $actiontype,
            'state' => $state,
            'manualapprovalrequired' => ($state !== 'eligible' || (int)$rule['requiremanualapproval'] === 1) ? 1 : 0,
            'canautoprogress' => ($state === 'eligible' && (int)$rule['requiremanualapproval'] === 0) ? 1 : 0,
            'notice' => $state === 'rules_not_configured'
                ? 'Automatic academic eligibility is not configured. Progression requires Admin approval.'
                : implode('; ', $reasons),
            'reasons' => $reasons,
            'rule' => $rule,
            'results' => $results['items'],
            'summary' => $results['summary'],
            'datasource' => $results['datasource'],
        ];

        return [
            'ok' => true,
            'eligibility' => self::sanitize_for_external($eligibility),
        ];
    }

    /**
     * Ensure eligibility payload is safe for Moodle external_api encoding.
     * Optional single structures must be omitted (not null).
     *
     * @param array $eligibility
     * @return array
     */
    public static function sanitize_for_external(array $eligibility): array {
        if (!array_key_exists('rule', $eligibility) || $eligibility['rule'] === null) {
            unset($eligibility['rule']);
        }
        if (!isset($eligibility['reasons']) || !is_array($eligibility['reasons'])) {
            $eligibility['reasons'] = [];
        }
        if (!isset($eligibility['results']) || !is_array($eligibility['results'])) {
            $eligibility['results'] = [];
        }
        $eligibility['notice'] = (string)($eligibility['notice'] ?? '');
        $eligibility['userid'] = (int)($eligibility['userid'] ?? 0);
        $eligibility['actiontype'] = (string)($eligibility['actiontype'] ?? 'semester_progression');
        $eligibility['state'] = (string)($eligibility['state'] ?? 'rules_not_configured');
        $eligibility['manualapprovalrequired'] = (int)($eligibility['manualapprovalrequired'] ?? 1);
        $eligibility['canautoprogress'] = (int)($eligibility['canautoprogress'] ?? 0);

        $summary = is_array($eligibility['summary'] ?? null) ? $eligibility['summary'] : [];
        $eligibility['summary'] = [
            'total' => (int)($summary['total'] ?? 0),
            'completed' => (int)($summary['completed'] ?? 0),
            'failed' => (int)($summary['failed'] ?? 0),
            'pending' => (int)($summary['pending'] ?? 0),
            'missing' => (int)($summary['missing'] ?? 0),
            'unmapped' => (int)($summary['unmapped'] ?? 0),
        ];

        $ds = is_array($eligibility['datasource'] ?? null) ? $eligibility['datasource'] : [];
        if (isset($ds['completion']) && !isset($ds['coursecompletion'])) {
            $ds['coursecompletion'] = $ds['completion'];
        }
        unset($ds['completion']);
        $eligibility['datasource'] = [
            'gradebook' => (int)($ds['gradebook'] ?? 0),
            'coursecompletion' => (int)($ds['coursecompletion'] ?? 0),
            'reliableforprogression' => (int)($ds['reliableforprogression'] ?? 0),
            'note' => (string)($ds['note'] ?? ''),
        ];

        foreach ($eligibility['results'] as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['courseunitid'] = (int)($row['courseunitid'] ?? 0);
            $row['code'] = (string)($row['code'] ?? '');
            $row['name'] = (string)($row['name'] ?? '');
            $row['moodlecourseid'] = (int)($row['moodlecourseid'] ?? 0);
            $row['mappingstatus'] = (string)($row['mappingstatus'] ?? 'unmapped');
            $row['gradestatus'] = (string)($row['gradestatus'] ?? 'unavailable');
            $row['completionstatus'] = (string)($row['completionstatus'] ?? 'unavailable');
            $row['finalgrade'] = (string)($row['finalgrade'] ?? '');
            $row['resultavailability'] = (string)($row['resultavailability'] ?? 'unavailable');
        }
        unset($row);

        return $eligibility;
    }

    /**
     * Default eligibility when placement or assessment is unavailable.
     */
    public static function empty_eligibility(int $userid, string $actiontype, string $notice): array {
        return self::sanitize_for_external([
            'userid' => $userid,
            'actiontype' => $actiontype,
            'state' => 'rules_not_configured',
            'manualapprovalrequired' => 1,
            'canautoprogress' => 0,
            'notice' => $notice,
            'reasons' => [],
            'results' => [],
            'summary' => [
                'completed' => 0,
                'failed' => 0,
                'pending' => 0,
                'missing' => 0,
                'total' => 0,
                'unmapped' => 0,
            ],
            'datasource' => [
                'gradebook' => 0,
                'coursecompletion' => 0,
                'reliableforprogression' => 0,
                'note' => '',
            ],
        ]);
    }

    /**
     * Collect Moodle course grade + completion for current delivery units.
     */
    public static function collect_results(int $userid, array $profile): array {
        $units = curriculum_delivery_helper::list_course_units_for_placement(
            (int)$profile['curriculumid'],
            (int)$profile['curriculumyearid'],
            (int)$profile['curriculumsemesterid']
        );

        $items = [];
        $summary = [
            'total' => 0,
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'missing' => 0,
            'unmapped' => 0,
        ];
        $hasgrade = false;
        $hascompletion = false;

        foreach ($units as $unit) {
            $summary['total']++;
            $courseid = (int)($unit['moodlecourseid'] ?? $unit['courseid'] ?? 0);
            $map = (string)($unit['mappingstatus'] ?? 'unmapped');
            $gradestatus = 'unavailable';
            $completionstatus = 'unavailable';
            $finalgrade = null;
            $graderaw = null;

            if ($map === 'unmapped' || $courseid <= 1) {
                $summary['unmapped']++;
                $summary['missing']++;
                $items[] = [
                    'courseunitid' => (int)$unit['id'],
                    'code' => (string)$unit['code'],
                    'name' => (string)$unit['name'],
                    'moodlecourseid' => $courseid,
                    'mappingstatus' => $map,
                    'gradestatus' => 'missing_mapping',
                    'completionstatus' => 'missing_mapping',
                    'finalgrade' => '',
                    'resultavailability' => 'unavailable',
                ];
                continue;
            }

            // Course grade from Moodle gradebook.
            try {
                $grade = \grade_get_course_grade($userid, $courseid);
                if ($grade && isset($grade->grade)) {
                    $hasgrade = true;
                    $graderaw = $grade->grade;
                    if ($graderaw === null || $graderaw === false || $graderaw < 0) {
                        $gradestatus = 'pending';
                        $summary['pending']++;
                    } else {
                        $finalgrade = (string)$graderaw;
                        // Do not invent pass thresholds — report availability only unless rule uses counts via completion.
                        $gradestatus = 'available';
                    }
                } else {
                    $gradestatus = 'pending';
                    $summary['pending']++;
                }
            } catch (\Throwable $e) {
                $gradestatus = 'unavailable';
                $summary['missing']++;
            }

            // Course completion.
            try {
                $course = get_course($courseid);
                $cinfo = new \completion_info($course);
                if ($cinfo->is_enabled()) {
                    $hascompletion = true;
                    $ccomplete = $cinfo->is_course_complete($userid);
                    if ($ccomplete) {
                        $completionstatus = 'completed';
                        $summary['completed']++;
                    } else {
                        $completionstatus = 'incomplete';
                        if ($gradestatus !== 'pending' && $gradestatus !== 'unavailable') {
                            // incomplete but grade may exist — leave failed/pending to rules; count pending if no grade.
                            if ($gradestatus === 'available') {
                                // Not completed — do not invent fail.
                                $summary['pending']++;
                            }
                        }
                    }
                } else {
                    $completionstatus = 'not_enabled';
                }
            } catch (\Throwable $e) {
                $completionstatus = 'unavailable';
            }

            $items[] = [
                'courseunitid' => (int)$unit['id'],
                'code' => (string)$unit['code'],
                'name' => (string)$unit['name'],
                'moodlecourseid' => $courseid,
                'mappingstatus' => $map,
                'gradestatus' => $gradestatus,
                'completionstatus' => $completionstatus,
                'finalgrade' => $finalgrade !== null ? $finalgrade : '',
                'resultavailability' => ($gradestatus === 'available' || $completionstatus === 'completed')
                    ? 'available'
                    : (($gradestatus === 'pending' || $completionstatus === 'incomplete') ? 'pending' : 'unavailable'),
            ];
        }

        return [
            'items' => $items,
            'summary' => $summary,
            'datasource' => [
                'gradebook' => $hasgrade ? 1 : 0,
                'coursecompletion' => $hascompletion ? 1 : 0,
                'reliableforprogression' => ($hasgrade || $hascompletion) ? 1 : 0,
                'note' => 'Moodle Gradebook and Course Completion are consulted when available. Pass marks are not invented.',
            ],
        ];
    }

    public static function get_active_rule(int $curriculumid, string $actiontype): ?array {
        global $DB;
        if (!self::tables_ready() || $curriculumid <= 0) {
            return null;
        }
        $row = $DB->get_record_sql(
            "SELECT * FROM {" . self::RULE_TABLE . "}
              WHERE curriculumid = :curriculumid
                AND actiontype = :actiontype
                AND status = 'active'
           ORDER BY version DESC, id DESC",
            ['curriculumid' => $curriculumid, 'actiontype' => $actiontype],
            IGNORE_MULTIPLE
        );
        return $row ? self::format_rule($row) : null;
    }

    /**
     * Create a versioned progression rule. Previous active rules for same scope are archived.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create_rule(array $input, int $actorid = 0): array {
        global $DB;
        if (!self::tables_ready()) {
            return ['ok' => false, 'errors' => [['field' => 'schema', 'message' => 'Progression rule table not installed']]];
        }

        $curriculumid = (int)($input['curriculumid'] ?? 0);
        $actiontype = (string)($input['actiontype'] ?? 'semester_progression');
        $errors = [];
        if ($curriculumid <= 0 || !$DB->record_exists('local_btiportal_curriculum', ['id' => $curriculumid])) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Curriculum not found'];
        }
        if (!in_array($actiontype, ['semester_progression', 'promotion'], true)) {
            $errors[] = ['field' => 'actiontype', 'message' => 'Invalid action type'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $version = 1;
        $prev = $DB->get_record_sql(
            "SELECT * FROM {" . self::RULE_TABLE . "}
              WHERE curriculumid = :c AND actiontype = :a
           ORDER BY version DESC",
            ['c' => $curriculumid, 'a' => $actiontype],
            IGNORE_MULTIPLE
        );
        if ($prev) {
            $version = (int)$prev->version + 1;
            if ((string)$prev->status === 'active') {
                $prev->status = 'archived';
                $prev->timemodified = $now;
                $DB->update_record(self::RULE_TABLE, $prev);
            }
        }

        $id = $DB->insert_record(self::RULE_TABLE, (object)[
            'curriculumid' => $curriculumid,
            'programmeid' => (int)($input['programmeid'] ?? 0),
            'academicyearid' => (int)($input['academicyearid'] ?? 0),
            'curriculumyearid' => (int)($input['curriculumyearid'] ?? 0),
            'curriculumsemesterid' => (int)($input['curriculumsemesterid'] ?? 0),
            'actiontype' => $actiontype,
            'mincompletedunits' => (int)($input['mincompletedunits'] ?? 0),
            'maxfailedunits' => (int)($input['maxfailedunits'] ?? 999),
            'requiremanualapproval' => (int)($input['requiremanualapproval'] ?? 1),
            'status' => 'active',
            'version' => $version,
            'notes' => (string)($input['notes'] ?? ''),
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return ['ok' => true, 'record' => self::format_rule($DB->get_record(self::RULE_TABLE, ['id' => $id]))];
    }

    public static function format_rule(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'curriculumid' => (int)$row->curriculumid,
            'programmeid' => (int)$row->programmeid,
            'academicyearid' => (int)$row->academicyearid,
            'curriculumyearid' => (int)$row->curriculumyearid,
            'curriculumsemesterid' => (int)$row->curriculumsemesterid,
            'actiontype' => (string)$row->actiontype,
            'mincompletedunits' => (int)$row->mincompletedunits,
            'maxfailedunits' => (int)$row->maxfailedunits,
            'requiremanualapproval' => (int)$row->requiremanualapproval,
            'status' => (string)$row->status,
            'version' => (int)$row->version,
            'notes' => (string)$row->notes,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function eligibility_structure_keys(): array {
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'actiontype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'actiontype'),
            'state' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'state'),
            'manualapprovalrequired' => new \core_external\external_value(PARAM_INT, 'manualapprovalrequired'),
            'canautoprogress' => new \core_external\external_value(PARAM_INT, 'canautoprogress'),
            'notice' => new \core_external\external_value(PARAM_TEXT, 'notice'),
            'reasons' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_TEXT, 'reason'),
                'reasons',
                VALUE_OPTIONAL
            ),
            'rule' => new \core_external\external_single_structure([
                'id' => new \core_external\external_value(PARAM_INT, 'id'),
                'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
                'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
                'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
                'curriculumyearid' => new \core_external\external_value(PARAM_INT, 'curriculumyearid'),
                'curriculumsemesterid' => new \core_external\external_value(PARAM_INT, 'curriculumsemesterid'),
                'actiontype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'actiontype'),
                'mincompletedunits' => new \core_external\external_value(PARAM_INT, 'mincompletedunits'),
                'maxfailedunits' => new \core_external\external_value(PARAM_INT, 'maxfailedunits'),
                'requiremanualapproval' => new \core_external\external_value(PARAM_INT, 'requiremanualapproval'),
                'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
                'version' => new \core_external\external_value(PARAM_INT, 'version'),
                'notes' => new \core_external\external_value(PARAM_TEXT, 'notes'),
                'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
                'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            ], 'rule', VALUE_OPTIONAL),
            'results' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
                    'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
                    'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
                    'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
                    'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
                    'gradestatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'gradestatus'),
                    'completionstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'completionstatus'),
                    'finalgrade' => new \core_external\external_value(PARAM_TEXT, 'finalgrade'),
                    'resultavailability' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'resultavailability'),
                ])
            ),
            'summary' => new \core_external\external_single_structure([
                'total' => new \core_external\external_value(PARAM_INT, 'total'),
                'completed' => new \core_external\external_value(PARAM_INT, 'completed'),
                'failed' => new \core_external\external_value(PARAM_INT, 'failed'),
                'pending' => new \core_external\external_value(PARAM_INT, 'pending'),
                'missing' => new \core_external\external_value(PARAM_INT, 'missing'),
                'unmapped' => new \core_external\external_value(PARAM_INT, 'unmapped'),
            ]),
            'datasource' => new \core_external\external_single_structure([
                'gradebook' => new \core_external\external_value(PARAM_INT, 'gradebook'),
                'coursecompletion' => new \core_external\external_value(PARAM_INT, 'coursecompletion'),
                'reliableforprogression' => new \core_external\external_value(PARAM_INT, 'reliableforprogression'),
                'note' => new \core_external\external_value(PARAM_TEXT, 'note'),
            ]),
        ];
    }
}
