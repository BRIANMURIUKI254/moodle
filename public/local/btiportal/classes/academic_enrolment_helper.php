<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Academic enrolment preview/execution driven by the Student Academic Delivery Plan.
 *
 * Distinguishes academic operation records from Moodle enrolment execution results.
 * Never deletes Moodle users, grades, or completed learning history.
 */
class academic_enrolment_helper {
    public const OPS_TABLE = 'local_btiportal_enrol_ops';

    /**
     * @return array{ok:bool,preview?:array,errors?:list}
     */
    public static function preview(int $userid, string $operationtype = 'manual_reconciliation'): array {
        if (!in_array($operationtype, academic_constants::ENROLMENT_OPERATION_TYPES, true)) {
            return ['ok' => false, 'errors' => [['field' => 'operationtype', 'message' => 'Invalid enrolment operation type']]];
        }

        $planresult = student_delivery_plan_helper::get_plan($userid);
        if (!$planresult['ok']) {
            return $planresult;
        }
        $plan = $planresult['plan'];

        $already = [];
        $toenrol = [];
        $skipped = [];
        $manual = [];

        foreach ($plan['items'] as $item) {
            $row = [
                'courseunitid' => $item['courseunitid'],
                'code' => $item['code'],
                'name' => $item['name'],
                'moodlecourseid' => $item['moodlecourseid'],
                'moodlecoursename' => $item['moodlecoursename'],
                'requirementtype' => $item['requirementtype'],
                'reason' => '',
            ];
            switch ($item['enrolmentstatus']) {
                case 'enrolled':
                    $row['reason'] = 'Already enrolled';
                    $already[] = $row;
                    break;
                case 'not_enrolled':
                case 'ended':
                    if ($item['enrolmentaction'] === 'enrol' || $item['enrolmentaction'] === 'reenroll') {
                        $toenrol[] = $row;
                    } else {
                        $row['reason'] = 'Action: ' . $item['enrolmentaction'];
                        $manual[] = $row;
                    }
                    break;
                case 'unmapped':
                    $row['reason'] = 'Course Unit is not mapped to Moodle.';
                    $skipped[] = $row;
                    break;
                case 'missing_moodle_course':
                    $row['reason'] = 'Mapped Moodle Course no longer exists';
                    $skipped[] = $row;
                    break;
                case 'manual_review':
                    $row['reason'] = 'Requires manual review / elective selection';
                    $manual[] = $row;
                    break;
                default:
                    $row['reason'] = 'Status: ' . $item['enrolmentstatus'];
                    $skipped[] = $row;
            }
        }

        return [
            'ok' => true,
            'preview' => [
                'userid' => $userid,
                'operationtype' => $operationtype,
                'canenrol' => $plan['canenrol'] && count($toenrol) > 0,
                'blockers' => $plan['blockers'],
                'profile' => $plan['profile'],
                'alreadyenrolled' => $already,
                'toenrol' => $toenrol,
                'skipped' => $skipped,
                'manualreview' => $manual,
                'warnings' => [
                    'Existing Moodle grades will not be deleted.',
                    'Existing completed Course history will be preserved.',
                    'The Student Moodle account will remain unchanged.',
                    'Unmapped Course Units cannot be enrolled automatically.',
                    'Elective Course Units require a valid selection before enrolment.',
                ],
                'plansummary' => $plan['summary'],
            ],
        ];
    }

    /**
     * Execute approved enrolments. Recalculates the plan on the backend before applying.
     *
     * @param list<int> $courseunitids Optional subset; empty = all auto-enrolable items.
     * @return array{ok:bool,result?:array,errors?:list}
     */
    public static function execute(
        int $userid,
        string $operationtype = 'manual_reconciliation',
        array $courseunitids = [],
        int $actorid = 0
    ): array {
        global $DB;

        $preview = self::preview($userid, $operationtype);
        if (!$preview['ok']) {
            return $preview;
        }
        $p = $preview['preview'];
        if (!$p['canenrol'] && empty($p['toenrol'])) {
            if (!empty($p['blockers'])) {
                return ['ok' => false, 'errors' => $p['blockers']];
            }
            // Nothing to enrol is a successful no-op.
        }
        if (!empty($p['blockers'])) {
            return ['ok' => false, 'errors' => $p['blockers']];
        }

        $allowed = [];
        foreach ($p['toenrol'] as $row) {
            if ($courseunitids && !in_array((int)$row['courseunitid'], array_map('intval', $courseunitids), true)) {
                continue;
            }
            $allowed[] = $row;
        }

        $enrolled = [];
        $failed = [];
        $already = $p['alreadyenrolled'];

        foreach ($allowed as $row) {
            $courseid = (int)$row['moodlecourseid'];
            $ok = self::enrol_student($userid, $courseid);
            if ($ok['ok']) {
                $enrolled[] = array_merge($row, ['message' => $ok['message']]);
            } else {
                $failed[] = array_merge($row, ['message' => $ok['message']]);
            }
        }

        $status = 'completed';
        if ($failed && $enrolled) {
            $status = 'partial';
        } else if ($failed && !$enrolled) {
            $status = 'failed';
        } else if (!$enrolled && !$failed) {
            $status = 'skipped';
        }

        $oprecord = null;
        if ($DB->get_manager()->table_exists(self::OPS_TABLE)) {
            $profile = $p['profile'];
            $now = time();
            $opid = $DB->insert_record(self::OPS_TABLE, (object)[
                'userid' => $userid,
                'academicyearid' => (int)($profile['academicyearid'] ?? 0),
                'semesterid' => (int)($profile['semesterid'] ?? 0),
                'operationtype' => $operationtype,
                'status' => $status,
                'summary' => json_encode([
                    'enrolled' => array_column($enrolled, 'moodlecourseid'),
                    'failed' => array_column($failed, 'moodlecourseid'),
                    'already' => array_column($already, 'moodlecourseid'),
                    'skipped' => array_column($p['skipped'], 'moodlecourseid'),
                    'manual' => array_column($p['manualreview'], 'moodlecourseid'),
                ]),
                'initiatedby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $oprecord = self::get_operation($opid);
        }

        $updated = student_delivery_plan_helper::get_plan($userid);

        return [
            'ok' => $status !== 'failed',
            'result' => [
                'userid' => $userid,
                'moodleuserid' => $userid,
                'operationtype' => $operationtype,
                'status' => $status,
                'enrolled' => $enrolled,
                'alreadyenrolled' => $already,
                'skipped' => $p['skipped'],
                'manualreview' => $p['manualreview'],
                'failed' => $failed,
                'operation' => $oprecord,
                'plan' => $updated['ok'] ? $updated['plan'] : null,
                'gradespreserved' => 1,
                'historypreserved' => 1,
                'userdeleted' => 0,
            ],
        ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private static function enrol_student(int $userid, int $courseid): array {
        $result = enrolment_transition_helper::ensure_student_enrolment($userid, $courseid);
        return ['ok' => !empty($result['ok']), 'message' => (string)($result['message'] ?? '')];
    }

    public static function get_operation(int $id): ?array {
        global $DB;
        if (!$DB->get_manager()->table_exists(self::OPS_TABLE)) {
            return null;
        }
        $row = $DB->get_record(self::OPS_TABLE, ['id' => $id]);
        return $row ? self::format_operation($row) : null;
    }

    public static function format_operation(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'academicyearid' => (int)$row->academicyearid,
            'semesterid' => (int)$row->semesterid,
            'operationtype' => (string)$row->operationtype,
            'status' => (string)$row->status,
            'summary' => (string)$row->summary,
            'initiatedby' => (int)$row->initiatedby,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function preview_structure_keys(): array {
        $courserow = [
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'moodlecoursename' => new \core_external\external_value(PARAM_TEXT, 'moodlecoursename'),
            'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
            'reason' => new \core_external\external_value(PARAM_TEXT, 'reason'),
        ];
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'operationtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'operationtype'),
            'canenrol' => new \core_external\external_value(PARAM_BOOL, 'canenrol'),
            'blockers' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'field' => new \core_external\external_value(PARAM_TEXT, 'field'),
                    'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
                ])
            ),
            'profile' => new \core_external\external_single_structure(student_profile_helper::structure_keys()),
            'alreadyenrolled' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'toenrol' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'skipped' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'manualreview' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'warnings' => new \core_external\external_multiple_structure(
                new \core_external\external_value(PARAM_TEXT, 'warning')
            ),
            'plansummary' => new \core_external\external_single_structure([
                'expected' => new \core_external\external_value(PARAM_INT, 'expected'),
                'enrolled' => new \core_external\external_value(PARAM_INT, 'enrolled'),
                'not_enrolled' => new \core_external\external_value(PARAM_INT, 'not_enrolled'),
                'unmapped' => new \core_external\external_value(PARAM_INT, 'unmapped'),
                'missing_moodle_course' => new \core_external\external_value(PARAM_INT, 'missing_moodle_course'),
                'manual_review' => new \core_external\external_value(PARAM_INT, 'manual_review'),
                'completed' => new \core_external\external_value(PARAM_INT, 'completed'),
                'ended' => new \core_external\external_value(PARAM_INT, 'ended'),
            ]),
        ];
    }

    public static function result_structure_keys(): array {
        $courserow = [
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'moodlecoursename' => new \core_external\external_value(PARAM_TEXT, 'moodlecoursename'),
            'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
            'reason' => new \core_external\external_value(PARAM_TEXT, 'reason', VALUE_OPTIONAL),
            'message' => new \core_external\external_value(PARAM_TEXT, 'message', VALUE_OPTIONAL),
        ];
        return [
            'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
            'moodleuserid' => new \core_external\external_value(PARAM_INT, 'moodleuserid'),
            'operationtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'operationtype'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'enrolled' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'alreadyenrolled' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'skipped' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'manualreview' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'failed' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure($courserow)
            ),
            'operation' => new \core_external\external_single_structure([
                'id' => new \core_external\external_value(PARAM_INT, 'id'),
                'userid' => new \core_external\external_value(PARAM_INT, 'userid'),
                'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
                'semesterid' => new \core_external\external_value(PARAM_INT, 'semesterid'),
                'operationtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'operationtype'),
                'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
                'summary' => new \core_external\external_value(PARAM_RAW, 'summary'),
                'initiatedby' => new \core_external\external_value(PARAM_INT, 'initiatedby'),
                'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
                'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            ], 'operation', VALUE_OPTIONAL),
            'plan' => new \core_external\external_single_structure(
                student_delivery_plan_helper::plan_structure_keys(),
                'Updated delivery plan',
                VALUE_OPTIONAL
            ),
            'gradespreserved' => new \core_external\external_value(PARAM_INT, 'gradespreserved'),
            'historypreserved' => new \core_external\external_value(PARAM_INT, 'historypreserved'),
            'userdeleted' => new \core_external\external_value(PARAM_INT, 'userdeleted'),
        ];
    }
}
