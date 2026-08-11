<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Self-scoped Student Portal aggregates — profile labels, delivery, electives,
 * progression notice, history, and institutional timetable for the signed-in user.
 *
 * Does not grant viewacademic. Does not expose other students' records.
 */
class student_portal_helper {
    /**
     * Enrich a raw academic profile with catalogue display labels.
     *
     * @param array|null $profile Formatted student_profile_helper row
     * @return array|null
     */
    public static function enrich_profile(?array $profile): ?array {
        if (!$profile) {
            return null;
        }
        $out = $profile;
        $out['schoolname'] = '';
        $out['schoolcode'] = '';
        $out['departmentname'] = '';
        $out['departmentcode'] = '';
        $out['programmename'] = '';
        $out['programmecode'] = '';
        $out['curriculumname'] = '';
        $out['curriculumversion'] = '';
        $out['curriculumyearlabel'] = '';
        $out['curriculumsemesterlabel'] = '';
        $out['academicyearname'] = '';
        $out['semestername'] = '';
        $out['yearofstudy'] = 0;
        $out['semestersequence'] = 0;

        if ((int)$profile['schoolid'] > 0) {
            $school = school_helper::get((int)$profile['schoolid']);
            if ($school) {
                $out['schoolname'] = (string)($school['name'] ?? '');
                $out['schoolcode'] = (string)($school['code'] ?? '');
            }
        }
        if ((int)$profile['departmentid'] > 0) {
            $dept = department_helper::get((int)$profile['departmentid']);
            if ($dept) {
                $out['departmentname'] = (string)($dept['name'] ?? '');
                $out['departmentcode'] = (string)($dept['code'] ?? '');
            }
        }
        if ((int)$profile['programmeid'] > 0) {
            $prog = programme_helper::get((int)$profile['programmeid']);
            if ($prog) {
                $out['programmename'] = (string)($prog['name'] ?? '');
                $out['programmecode'] = (string)($prog['code'] ?? '');
            }
        }
        if ((int)$profile['curriculumid'] > 0) {
            $curr = curriculum_helper::get((int)$profile['curriculumid']);
            if ($curr) {
                $out['curriculumname'] = (string)($curr['name'] ?? '');
                $out['curriculumversion'] = (string)($curr['version'] ?? '');
            }
        }
        if ((int)$profile['curriculumyearid'] > 0) {
            $cy = curriculum_structure_helper::get_year((int)$profile['curriculumyearid']);
            if ($cy) {
                $out['curriculumyearlabel'] = (string)($cy['name'] ?? ('Year ' . (int)($cy['yearnumber'] ?? 0)));
                $out['yearofstudy'] = (int)($cy['yearnumber'] ?? 0);
            }
        }
        if ((int)$profile['curriculumsemesterid'] > 0) {
            $cs = curriculum_structure_helper::get_semester((int)$profile['curriculumsemesterid']);
            if ($cs) {
                $out['curriculumsemesterlabel'] = (string)($cs['name'] ?? ('Semester ' . (int)($cs['semesternumber'] ?? 0)));
                $out['semestersequence'] = (int)($cs['semesternumber'] ?? 0);
            }
        }
        if ((int)$profile['academicyearid'] > 0) {
            $ay = academic_calendar_helper::get_year((int)$profile['academicyearid']);
            if ($ay) {
                $out['academicyearname'] = (string)($ay['name'] ?? '');
            }
        }
        if ((int)$profile['semesterid'] > 0) {
            $sem = academic_calendar_helper::get_semester((int)$profile['semesterid']);
            if ($sem) {
                $out['semestername'] = (string)($sem['name'] ?? '');
            }
        }
        return $out;
    }

    public static function enriched_profile_structure_keys(): array {
        $base = student_profile_helper::structure_keys();
        $extra = [
            'schoolname' => new \core_external\external_value(PARAM_TEXT, 'schoolname'),
            'schoolcode' => new \core_external\external_value(PARAM_TEXT, 'schoolcode'),
            'departmentname' => new \core_external\external_value(PARAM_TEXT, 'departmentname'),
            'departmentcode' => new \core_external\external_value(PARAM_TEXT, 'departmentcode'),
            'programmename' => new \core_external\external_value(PARAM_TEXT, 'programmename'),
            'programmecode' => new \core_external\external_value(PARAM_TEXT, 'programmecode'),
            'curriculumname' => new \core_external\external_value(PARAM_TEXT, 'curriculumname'),
            'curriculumversion' => new \core_external\external_value(PARAM_TEXT, 'curriculumversion'),
            'curriculumyearlabel' => new \core_external\external_value(PARAM_TEXT, 'curriculumyearlabel'),
            'curriculumsemesterlabel' => new \core_external\external_value(PARAM_TEXT, 'curriculumsemesterlabel'),
            'academicyearname' => new \core_external\external_value(PARAM_TEXT, 'academicyearname'),
            'semestername' => new \core_external\external_value(PARAM_TEXT, 'semestername'),
            'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy'),
            'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence'),
        ];
        return array_merge($base, $extra);
    }

    /**
     * Delivery status label derived from plan summary (honest, no invented metrics).
     */
    public static function delivery_status_from_plan(?array $plan): array {
        if (!$plan) {
            return [
                'code' => 'not_configured',
                'label' => 'Not configured',
                'message' => 'No academic delivery plan is available for your account yet.',
            ];
        }
        $summary = $plan['summary'] ?? [];
        $blockers = $plan['blockers'] ?? [];
        if (!empty($blockers) || empty($plan['canenrol'])) {
            return [
                'code' => 'incomplete_placement',
                'label' => 'Incomplete placement',
                'message' => 'Your academic placement is incomplete. Contact Academic Administration.',
            ];
        }
        $manual = (int)($summary['manual_review'] ?? 0);
        $unmapped = (int)($summary['unmapped'] ?? 0) + (int)($summary['missing_moodle_course'] ?? 0);
        $elective = (int)($summary['elective_required'] ?? 0);
        $notenrolled = (int)($summary['not_enrolled'] ?? 0);
        $enrolled = (int)($summary['enrolled'] ?? 0);
        $expected = (int)($summary['expected'] ?? 0);

        if ($manual > 0) {
            return [
                'code' => 'manual_review',
                'label' => 'Manual review required',
                'message' => 'Some course units need academic review before enrolment can complete.',
            ];
        }
        if ($elective > 0) {
            return [
                'code' => 'elective_required',
                'label' => 'Elective selection required',
                'message' => 'Select your electives before academic enrolment can be completed.',
            ];
        }
        if ($unmapped > 0) {
            return [
                'code' => 'unmapped',
                'label' => 'Unmapped course units',
                'message' => 'Some curriculum course units are not mapped to Moodle courses yet.',
            ];
        }
        if ($notenrolled > 0) {
            return [
                'code' => 'ready',
                'label' => 'Ready for enrolment',
                'message' => 'Mapped course units are waiting for academic enrolment by administration.',
            ];
        }
        if ($expected > 0 && $enrolled >= $expected) {
            return [
                'code' => 'enrolled',
                'label' => 'Enrolled',
                'message' => 'You are enrolled in the Moodle courses for your current delivery plan.',
            ];
        }
        if ($enrolled > 0) {
            return [
                'code' => 'partial',
                'label' => 'Partially enrolled',
                'message' => 'Some expected course units are enrolled; others may still be pending.',
            ];
        }
        return [
            'code' => 'ready',
            'label' => 'Ready',
            'message' => 'Your academic delivery plan is available.',
        ];
    }

    /**
     * Full self-scoped workspace for the Student Portal dashboard and academic pages.
     *
     * @return array
     */
    public static function get_workspace(int $userid, int $academicyearid = 0, int $semesterid = 0): array {
        global $DB, $USER;

        $userid = (int)$userid;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

        $rawprofile = student_profile_helper::get_by_userid($userid);

        // Unlock next curriculum semester when the institutional timeframe is due.
        $sync = ['advanced' => false, 'notice' => ''];
        if ($rawprofile) {
            $sync = student_semester_sync_helper::sync_if_due($userid, 0);
            if (!empty($sync['advanced'])) {
                $rawprofile = student_profile_helper::get_by_userid($userid);
            }
        }

        $profile = self::enrich_profile($rawprofile);

        $plan = null;
        $planresult = student_delivery_plan_helper::get_plan($userid);
        if (!empty($planresult['ok']) && !empty($planresult['plan'])) {
            // Keep plan.profile on the base structure keys (admin-compatible).
            $plan = $planresult['plan'];
        }

        $unitpathway = student_unit_pathway_helper::get_pathway($userid);
        $unitpathway['advanced'] = !empty($sync['advanced']) ? 1 : 0;
        $unitpathway['advancenotice'] = (string)($sync['notice'] ?? '');

        $deliverystatus = self::delivery_status_from_plan($plan);

        $yearid = $academicyearid > 0
            ? $academicyearid
            : (int)($profile['academicyearid'] ?? 0);
        $semid = $semesterid > 0
            ? $semesterid
            : (int)($profile['semesterid'] ?? 0);

        $electivegroups = [];
        $electiveselections = [];
        if ($profile && (int)$profile['curriculumid'] > 0) {
            $electivegroups = elective_helper::list_groups(
                (int)$profile['curriculumid'],
                (int)($profile['yearofstudy'] ?? 0),
                (int)($profile['semestersequence'] ?? 0),
                'active'
            );
            if ($yearid > 0 && $semid > 0) {
                $electiveselections = elective_helper::list_student_selections(
                    $userid,
                    $yearid,
                    $semid,
                    ''
                );
            }
        }

        $eligibility = null;
        $eligresult = progression_eligibility_helper::assess($userid, 'semester_progression');
        if (!empty($eligresult['eligibility']) && is_array($eligresult['eligibility'])) {
            $eligibility = progression_eligibility_helper::sanitize_for_external($eligresult['eligibility']);
        }

        $history = student_academic_history_helper::list_for_user($userid, null);

        $now = time();
        $daystart = strtotime('today', $now);
        $dayend = strtotime('tomorrow', $now) - 1;
        $weekstart = strtotime('monday this week', $now);
        $weekend = strtotime('sunday this week 23:59:59', $now);

        $todaysessions = timetable_helper::list_for_student($userid, [
            'datestart' => (int)$daystart,
            'dateend' => (int)$dayend,
            'status' => 'published',
            'academicyearid' => $yearid,
            'semesterid' => $semid,
        ]);
        // Also include scheduled synonym if statuses differ in data.
        if (!$todaysessions) {
            $todaysessions = timetable_helper::list_for_student($userid, [
                'datestart' => (int)$daystart,
                'dateend' => (int)$dayend,
                'academicyearid' => $yearid,
                'semesterid' => $semid,
            ]);
            $todaysessions = array_values(array_filter(
                $todaysessions,
                static fn($s) => !in_array(($s['status'] ?? ''), ['cancelled', 'archived', 'draft'], true)
            ));
        }

        $weeksessions = timetable_helper::list_for_student($userid, [
            'datestart' => (int)$weekstart,
            'dateend' => (int)$weekend,
            'academicyearid' => $yearid,
            'semesterid' => $semid,
        ]);
        $weeksessions = array_values(array_filter(
            $weeksessions,
            static fn($s) => !in_array(($s['status'] ?? ''), ['cancelled', 'archived', 'draft'], true)
        ));

        $years = academic_calendar_helper::list_years('active');
        $semesters = $yearid > 0
            ? academic_calendar_helper::list_semesters($yearid, 'active')
            : [];

        $period = static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'code' => (string)($row['code'] ?? ''),
                'iscurrent' => (int)($row['iscurrent'] ?? 0),
                'academicyearid' => (int)($row['academicyearid'] ?? 0),
            ];
        };

        $currentyear = null;
        foreach ($years as $y) {
            if ((int)($y['iscurrent'] ?? 0) === 1) {
                $currentyear = $y;
                break;
            }
        }
        $currentsemester = null;
        foreach ($semesters as $s) {
            if ((int)($s['iscurrent'] ?? 0) === 1) {
                $currentsemester = $s;
                break;
            }
        }

        return [
            'userid' => $userid,
            'fullname' => fullname($user),
            'firstname' => (string)$user->firstname,
            'lastname' => (string)$user->lastname,
            'email' => (string)$user->email,
            'username' => (string)$user->username,
            'idnumber' => (string)($user->idnumber ?? ''),
            'phone1' => (string)($user->phone1 ?? ''),
            'profileimageurl' => '',
            'hasprofile' => $profile ? 1 : 0,
            'profile' => $profile,
            'deliverystatus' => $deliverystatus,
            'deliveryplan' => $plan,
            'unitpathway' => $unitpathway,
            'electivegroups' => $electivegroups,
            'electiveselections' => $electiveselections,
            'eligibility' => $eligibility,
            'history' => array_slice($history, 0, 50),
            'historycount' => count($history),
            'todaysessions' => $todaysessions,
            'upcomingtimetablesessions' => $weeksessions,
            'academicyearid' => $yearid,
            'semesterid' => $semid,
            'academicyears' => array_map($period, $years),
            'semesters' => array_map($period, $semesters),
            'currentacademicyear' => $currentyear ? $period($currentyear) : [
                'id' => 0, 'name' => '', 'code' => '', 'iscurrent' => 0, 'academicyearid' => 0,
            ],
            'currentsemester' => $currentsemester ? $period($currentsemester) : [
                'id' => 0, 'name' => '', 'code' => '', 'iscurrent' => 0, 'academicyearid' => 0,
            ],
        ];
    }

    public static function period_structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'iscurrent' => new \core_external\external_value(PARAM_INT, 'iscurrent'),
            'academicyearid' => new \core_external\external_value(PARAM_INT, 'academicyearid'),
        ];
    }

    public static function delivery_status_structure_keys(): array {
        return [
            'code' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'code'),
            'label' => new \core_external\external_value(PARAM_TEXT, 'label'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'message'),
        ];
    }

    /**
     * Ensure delivery plan nested profile/items are encode-safe for Moodle WS.
     *
     * @param array $plan
     * @return array
     */
    public static function sanitize_delivery_plan_for_external(array $plan): array {
        if (isset($plan['profile']) && is_array($plan['profile'])) {
            $p = $plan['profile'];
            foreach ([
                'entrytype', 'studymode', 'deliverymode', 'campus', 'intake', 'scholarship', 'sponsor',
            ] as $key) {
                if (!array_key_exists($key, $p)) {
                    $p[$key] = '';
                } else {
                    $p[$key] = (string)$p[$key];
                }
            }
            $plan['profile'] = $p;
        }
        if (!isset($plan['blockers']) || !is_array($plan['blockers'])) {
            $plan['blockers'] = [];
        }
        if (!isset($plan['items']) || !is_array($plan['items'])) {
            $plan['items'] = [];
        }
        foreach ($plan['items'] as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $item['credithours'] = (int)($item['credithours'] ?? 0);
            $item['electiveselected'] = (int)($item['electiveselected'] ?? 0);
            $item['code'] = (string)($item['code'] ?? '');
            $item['name'] = (string)($item['name'] ?? '');
            $item['moodlecoursename'] = (string)($item['moodlecoursename'] ?? '');
            $item['moodlecourseshortname'] = (string)($item['moodlecourseshortname'] ?? '');
            $item['completion'] = (string)($item['completion'] ?? 'unknown');
        }
        unset($item);
        if (!isset($plan['electivegroups']) || !is_array($plan['electivegroups'])) {
            $plan['electivegroups'] = [];
        }
        $plan['electiveincompletegroups'] = (int)($plan['electiveincompletegroups'] ?? 0);
        $plan['warning'] = (string)($plan['warning'] ?? '');
        $plan['canenrol'] = !empty($plan['canenrol']);
        return $plan;
    }
}
