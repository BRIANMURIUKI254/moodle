<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Lightweight student portal context for dashboard hero + fees gate (no pathway enrol side effects).
 */
class student_context_helper {
    /**
     * @return array{profile:array,fees:array,access:array}
     */
    public static function get_for_user(int $userid): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
        $raw = student_profile_helper::get_by_userid($userid);
        $profile = self::build_profile_payload($user, $raw);
        $semesterfees = student_semester_fees_helper::current_semester_status($userid);
        $fees = [
            'status' => $semesterfees['status'],
            'paid_at' => (int)$semesterfees['paid_at'],
            'source' => '',
            'reference' => '',
        ];
        $paidmeta = student_fees_helper::get_amount_paid_minor($userid);
        $allowed = fees_gate_helper::access_allowed($userid);
        $reason = null;
        if (fees_gate_helper::is_enabled()
            && fees_gate_helper::is_subject_to_gate($userid)
            && !student_fees_helper::is_paid($userid)) {
            $reason = 'FEES_UNPAID';
        }

        return [
            'profile' => $profile,
            'fees' => [
                'status' => $fees['status'],
                'paid_at' => $fees['paid_at'] > 0 ? $fees['paid_at'] : null,
                'amount' => $semesterfees['amount'] > 0 ? $semesterfees['amount'] : null,
                'currency' => $semesterfees['amount'] > 0 ? $semesterfees['currency'] : null,
                'due_at' => $semesterfees['due_at'] > 0 ? $semesterfees['due_at'] : null,
                'amount_paid' => (int)$paidmeta['amount_paid'],
                'amount_paid_currency' => (string)$paidmeta['currency'],
                'semester_name' => $profile['semester'] ?? null,
                'published' => !empty($semesterfees['published']),
            ],
            'access' => [
                'allowed' => $allowed ? true : false,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @param \stdClass|null $user
     * @param array|null $raw profile row
     */
    private static function build_profile_payload(?\stdClass $user, ?array $raw): array {
        $admissionno = $user && trim((string)($user->idnumber ?? '')) !== ''
            ? trim((string)$user->idnumber)
            : null;

        if (!$raw || (int)($raw['id'] ?? 0) <= 0) {
            return [
                'admission_no' => $admissionno,
                'programme' => null,
                'school' => null,
                'department' => null,
                'year_of_study' => null,
                'academic_year' => null,
                'semester' => null,
                'advisor' => null,
            ];
        }

        $enriched = student_portal_helper::enrich_profile($raw);
        $year = null;
        if (!empty($enriched['yearofstudy']) && (int)$enriched['yearofstudy'] > 0) {
            $year = (string)(int)$enriched['yearofstudy'];
        } else if (!empty($enriched['curriculumyearlabel'])) {
            $year = (string)$enriched['curriculumyearlabel'];
        }

        $advisor = null;
        $advisorid = (int)($enriched['advisoruserid'] ?? 0);
        if ($advisorid > 0) {
            $advisoruser = \core_user::get_user($advisorid, '*', MUST_EXIST);
            $advisor = fullname($advisoruser);
        }

        return [
            'admission_no' => $admissionno,
            'programme' => self::null_if_empty($enriched['programmename'] ?? $enriched['programmecode'] ?? ''),
            'school' => self::null_if_empty($enriched['schoolname'] ?? $enriched['schoolcode'] ?? ''),
            'department' => self::null_if_empty($enriched['departmentname'] ?? $enriched['departmentcode'] ?? ''),
            'year_of_study' => $year,
            'academic_year' => self::null_if_empty($enriched['academicyearname'] ?? ''),
            'semester' => self::null_if_empty($enriched['semestername'] ?? $enriched['curriculumsemesterlabel'] ?? ''),
            'advisor' => $advisor,
        ];
    }

    private static function null_if_empty(string $value): ?string {
        $v = trim($value);
        return $v === '' ? null : $v;
    }
}