<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Reusable academic enrolment reconciliation.
 *
 * Wraps academic_enrolment_helper — does not duplicate the enrolment engine.
 * Optionally applies end/unenrol actions from a delivery comparison policy.
 */
class enrolment_reconciliation_helper {
    /**
     * @return array{ok:bool,preview?:array,errors?:list}
     */
    public static function preview(int $userid, string $operationtype = 'manual_reconciliation'): array {
        return academic_enrolment_helper::preview($userid, $operationtype);
    }

    /**
     * Preview reconciliation using a delivery comparison (transfer/return/readmit).
     *
     * @return array{ok:bool,preview?:array,errors?:list}
     */
    public static function preview_with_compare(
        int $userid,
        array $targetplacement,
        string $policy = 'preserve_and_enrol_new',
        string $operationtype = 'manual_reconciliation'
    ): array {
        $compare = academic_delivery_compare_helper::compare($userid, $targetplacement, $policy);
        if (!$compare['ok']) {
            return $compare;
        }
        $enrolpreview = academic_enrolment_helper::preview($userid, $operationtype);
        return [
            'ok' => true,
            'preview' => [
                'userid' => $userid,
                'operationtype' => $operationtype,
                'policy' => $policy,
                'comparison' => $compare['comparison'],
                'enrolment' => $enrolpreview['ok'] ? $enrolpreview['preview'] : null,
                'enrolmenterrors' => $enrolpreview['ok'] ? [] : ($enrolpreview['errors'] ?? []),
            ],
        ];
    }

    /**
     * Execute approved enrolment actions. Local academic changes are assumed already committed.
     *
     * @param list<int> $courseunitids
     * @param list<int> $endcourseids Moodle course IDs to end (suspend) when policy allows
     * @param list<int> $unenrolcourseids Moodle course IDs to hard-unenrol when explicitly confirmed
     * @return array{ok:bool,result?:array,errors?:list}
     */
    public static function execute(
        int $userid,
        string $operationtype = 'manual_reconciliation',
        array $courseunitids = [],
        int $actorid = 0,
        string $policy = 'preserve_and_enrol_new',
        array $endcourseids = [],
        array $unenrolcourseids = [],
        bool $confirmdestructive = false
    ): array {
        if (in_array($policy, ['end_active_and_enrol_new', 'unenrol_previous_and_enrol_new'], true)
            && !$confirmdestructive
            && ($endcourseids || $unenrolcourseids || $policy === 'unenrol_previous_and_enrol_new')
        ) {
            return [
                'ok' => false,
                'errors' => [[
                    'field' => 'confirmdestructive',
                    'message' => 'Destructive enrolment transition requires explicit Admin confirmation',
                ]],
            ];
        }

        $transitionpolicy = 'preserve';
        $courseidstounenrol = [];
        if ($policy === 'end_active_and_enrol_new') {
            $transitionpolicy = 'end_active';
            $courseidstounenrol = array_map('intval', $endcourseids);
        } else if ($policy === 'unenrol_previous_and_enrol_new') {
            $transitionpolicy = 'unenrol_previous';
            $courseidstounenrol = array_map('intval', $unenrolcourseids ?: $endcourseids);
        }

        $transitionresult = null;
        if ($transitionpolicy !== 'preserve' && $courseidstounenrol) {
            $transitionresult = enrolment_transition_helper::apply(
                $userid,
                $transitionpolicy,
                [],
                $courseidstounenrol
            );
        }

        $enrol = academic_enrolment_helper::execute($userid, $operationtype, $courseunitids, $actorid);
        if (!$enrol['ok']) {
            return [
                'ok' => false,
                'errors' => $enrol['errors'] ?? [['field' => 'enrolment', 'message' => 'Enrolment execution failed']],
                'result' => [
                    'transition' => $transitionresult,
                    'enrolment' => $enrol,
                ],
            ];
        }

        return [
            'ok' => true,
            'result' => [
                'operationid' => (int)($enrol['result']['operation']['id'] ?? 0),
                'status' => (string)($enrol['result']['operation']['status'] ?? $enrol['result']['status'] ?? 'completed'),
                'enrolled' => $enrol['result']['enrolled'] ?? [],
                'alreadyenrolled' => $enrol['result']['alreadyenrolled'] ?? [],
                'skipped' => $enrol['result']['skipped'] ?? [],
                'failed' => $enrol['result']['failed'] ?? [],
                'manualreview' => $enrol['result']['manualreview'] ?? [],
                'warnings' => $enrol['result']['warnings'] ?? [],
                'transition' => $transitionresult['ok'] ?? null ? ($transitionresult['result'] ?? null) : $transitionresult,
                'operation' => $enrol['result']['operation'] ?? null,
                'plan' => $enrol['result']['plan'] ?? null,
                'identitypreserved' => [
                    'useridunchanged' => 1,
                    'gradespreserved' => 1,
                    'historypreserved' => 1,
                    'userdeleted' => 0,
                ],
            ],
        ];
    }
}
