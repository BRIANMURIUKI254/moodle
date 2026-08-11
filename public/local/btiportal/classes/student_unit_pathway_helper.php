<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Current-semester units + units already studied for a student curriculum pathway.
 */
class student_unit_pathway_helper {
    /**
     * @return array{
     *   current:list<array>,
     *   studied:list<array>,
     *   upcoming:list<array>,
     *   currentyear:int,
     *   currentsemester:int
     * }
     */
    public static function get_pathway(int $userid): array {
        $empty = [
            'current' => [],
            'studied' => [],
            'upcoming' => [],
            'currentyear' => 0,
            'currentsemester' => 0,
        ];

        $profile = student_profile_helper::get_by_userid($userid);
        if (!$profile || (int)($profile['curriculumid'] ?? 0) <= 0) {
            return $empty;
        }

        $profile = student_delivery_plan_helper::resolve_placement_gaps($profile, false);
        $curriculumid = (int)$profile['curriculumid'];
        $yearid = (int)($profile['curriculumyearid'] ?? 0);
        $semid = (int)($profile['curriculumsemesterid'] ?? 0);

        $yearnumber = 0;
        $semesternumber = 0;
        global $DB;
        if ($yearid > 0) {
            $yearnumber = (int)$DB->get_field('local_btiportal_curriculum_year', 'yearnumber', ['id' => $yearid]);
        }
        if ($semid > 0) {
            $semesternumber = (int)$DB->get_field(
                'local_btiportal_curriculum_semester',
                'semesternumber',
                ['id' => $semid]
            );
        }

        $tree = curriculum_delivery_helper::get_curriculum_delivery($curriculumid);
        if (empty($tree['ok']) || empty($tree['record']['years'])) {
            return array_merge($empty, [
                'currentyear' => $yearnumber,
                'currentsemester' => $semesternumber,
            ]);
        }

        $current = [];
        $studied = [];
        $upcoming = [];

        foreach ($tree['record']['years'] as $year) {
            $yn = (int)($year['yearnumber'] ?? 0);
            foreach ($year['semesters'] ?? [] as $sem) {
                $sn = (int)($sem['semesternumber'] ?? 0);
                $units = array_map(
                    static fn(array $u): array => self::format_unit($u, $yn, $sn, $year, $sem),
                    $sem['courseunits'] ?? []
                );
                if ($yn === $yearnumber && $sn === $semesternumber) {
                    $current = array_merge($current, $units);
                } else if ($yn < $yearnumber || ($yn === $yearnumber && $sn < $semesternumber)) {
                    $studied = array_merge($studied, $units);
                } else if ($yn > $yearnumber || ($yn === $yearnumber && $sn > $semesternumber)) {
                    $upcoming = array_merge($upcoming, $units);
                }
            }
        }

        return [
            'current' => $current,
            'studied' => $studied,
            'upcoming' => $upcoming,
            'currentyear' => $yearnumber,
            'currentsemester' => $semesternumber,
        ];
    }

    /**
     * @param array $unit
     * @param array $year
     * @param array $sem
     */
    private static function format_unit(array $unit, int $yearnumber, int $semesternumber, array $year, array $sem): array {
        return [
            'courseunitid' => (int)($unit['id'] ?? 0),
            'code' => (string)($unit['code'] ?? ''),
            'name' => (string)($unit['name'] ?? ''),
            'credithours' => (int)($unit['credithours'] ?? 0),
            'requirementtype' => (string)($unit['requirementtype'] ?? ((int)($unit['isrequired'] ?? 1) === 1 ? 'required' : 'elective')),
            'moodlecourseid' => (int)($unit['moodlecourseid'] ?? $unit['courseid'] ?? 0),
            'mappingstatus' => (string)($unit['mappingstatus'] ?? 'unmapped'),
            'imageurl' => (string)($unit['imageurl'] ?? ''),
            'yearofstudy' => $yearnumber,
            'semestersequence' => $semesternumber,
            'curriculumyearlabel' => (string)($year['name'] ?? ('Year ' . $yearnumber)),
            'curriculumsemesterlabel' => (string)($sem['name'] ?? ('Semester ' . $semesternumber)),
        ];
    }

    public static function structure_keys(): array {
        return [
            'courseunitid' => new \core_external\external_value(PARAM_INT, 'courseunitid'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'credithours' => new \core_external\external_value(PARAM_INT, 'credithours'),
            'requirementtype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'requirementtype'),
            'moodlecourseid' => new \core_external\external_value(PARAM_INT, 'moodlecourseid'),
            'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'mappingstatus'),
            'imageurl' => new \core_external\external_value(PARAM_RAW, 'imageurl'),
            'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy'),
            'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence'),
            'curriculumyearlabel' => new \core_external\external_value(PARAM_TEXT, 'curriculumyearlabel'),
            'curriculumsemesterlabel' => new \core_external\external_value(PARAM_TEXT, 'curriculumsemesterlabel'),
        ];
    }

    public static function pathway_structure_keys(): array {
        $unit = new \core_external\external_single_structure(self::structure_keys());
        return [
            'current' => new \core_external\external_multiple_structure($unit),
            'studied' => new \core_external\external_multiple_structure($unit),
            'upcoming' => new \core_external\external_multiple_structure($unit),
            'currentyear' => new \core_external\external_value(PARAM_INT, 'currentyear'),
            'currentsemester' => new \core_external\external_value(PARAM_INT, 'currentsemester'),
            'advanced' => new \core_external\external_value(PARAM_INT, 'advanced'),
            'advancenotice' => new \core_external\external_value(PARAM_TEXT, 'advancenotice'),
        ];
    }
}
