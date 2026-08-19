<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Academic Course Unit — first-class record referencing Moodle course + section.
 * Auto-provisions Moodle course/section when courseid/sectionid are omitted.
 */
class academic_course_unit_helper {
    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function create(array $input, int $userid): array {
        global $DB;

        $code = trim((string)($input['code'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = (string)($input['description'] ?? '');
        $programmeid = (int)($input['programmeid'] ?? 0);
        $curriculumid = (int)($input['curriculumid'] ?? 0);
        $yearofstudy = (int)($input['yearofstudy'] ?? 0);
        $semestersequence = (int)($input['semestersequence'] ?? 0);
        $courseid = (int)($input['courseid'] ?? 0);
        $sectionid = (int)($input['sectionid'] ?? 0);
        $credithours = (float)($input['credithours'] ?? 0);
        $unittype = strtolower(trim((string)($input['unittype'] ?? 'core')));
        $status = strtolower(trim((string)($input['status'] ?? 'draft')));
        $isrequired = array_key_exists('isrequired', $input) ? (!empty($input['isrequired']) ? 1 : 0) : 1;

        $autoprovide = ($courseid <= 1);
        $errors = self::validate_refs(
            $code, $name, $programmeid, $curriculumid, $yearofstudy, $semestersequence,
            $courseid, $sectionid, $credithours, $unittype, $status, null, $autoprovide
        );
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Provision Moodle course + section when not mapped yet.
        if ($autoprovide) {
            try {
                $synced = moodle_catalogue_sync_helper::ensure_unit_moodle_course_and_section(
                    $programmeid,
                    $code,
                    $name,
                    $description
                );
                $courseid = (int)$synced['courseid'];
                $sectionid = (int)$synced['sectionid'];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'errors' => [[
                        'field' => 'courseid',
                        'message' => 'Could not sync Moodle course/section: ' . $e->getMessage(),
                    ]],
                ];
            }
        } else if ($sectionid <= 0) {
            try {
                $synced = moodle_catalogue_sync_helper::ensure_unit_section($courseid, $name);
                $sectionid = (int)$synced['sectionid'];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'errors' => [[
                        'field' => 'sectionid',
                        'message' => 'Could not sync Moodle section: ' . $e->getMessage(),
                    ]],
                ];
            }
        }

        // Re-validate Moodle refs after provisioning.
        $errors = self::validate_refs(
            $code, $name, $programmeid, $curriculumid, $yearofstudy, $semestersequence,
            $courseid, $sectionid, $credithours, $unittype, $status, null, false
        );
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $now = time();
        $id = $DB->insert_record('local_btiportal_course_unit', (object)[
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'programmeid' => $programmeid,
            'curriculumid' => $curriculumid,
            'yearofstudy' => $yearofstudy,
            'semestersequence' => $semestersequence,
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'credithours' => $credithours,
            'unittype' => $unittype,
            'status' => $status,
            'isrequired' => $isrequired,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => $userid,
            'modifiedby' => $userid,
        ]);

        curriculum_unit_helper::ensure_assignment_for_unit((int)$id);

        return ['ok' => true, 'record' => self::get($id)];
    }

    /**
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update(int $id, array $input, int $userid): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_course_unit', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course unit not found']]];
        }

        $code = array_key_exists('code', $input) ? trim((string)$input['code']) : (string)$row->code;
        $name = array_key_exists('name', $input) ? trim((string)$input['name']) : (string)$row->name;
        $programmeid = array_key_exists('programmeid', $input) ? (int)$input['programmeid'] : (int)$row->programmeid;
        $curriculumid = array_key_exists('curriculumid', $input) ? (int)$input['curriculumid'] : (int)$row->curriculumid;
        $yearofstudy = array_key_exists('yearofstudy', $input) ? (int)$input['yearofstudy'] : (int)$row->yearofstudy;
        $semestersequence = array_key_exists('semestersequence', $input)
            ? (int)$input['semestersequence'] : (int)$row->semestersequence;
        $courseid = array_key_exists('courseid', $input) ? (int)$input['courseid'] : (int)$row->courseid;
        $sectionid = array_key_exists('sectionid', $input) ? (int)$input['sectionid'] : (int)$row->sectionid;
        $credithours = array_key_exists('credithours', $input) ? (float)$input['credithours'] : (float)$row->credithours;
        $unittype = array_key_exists('unittype', $input)
            ? strtolower(trim((string)$input['unittype'])) : (string)$row->unittype;
        $status = array_key_exists('status', $input)
            ? strtolower(trim((string)$input['status'])) : (string)$row->status;

        $autoprovide = ($courseid <= 1);
        $errors = self::validate_refs(
            $code, $name, $programmeid, $curriculumid, $yearofstudy, $semestersequence,
            $courseid, $sectionid, $credithours, $unittype, $status, $id, $autoprovide
        );
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        if ($autoprovide) {
            try {
                $synced = moodle_catalogue_sync_helper::ensure_unit_moodle_course_and_section(
                    $programmeid,
                    $code,
                    $name,
                    (string)($input['description'] ?? $row->description ?? '')
                );
                $courseid = (int)$synced['courseid'];
                $sectionid = (int)$synced['sectionid'];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'errors' => [[
                        'field' => 'courseid',
                        'message' => 'Could not sync Moodle course/section: ' . $e->getMessage(),
                    ]],
                ];
            }
        } else if ($sectionid <= 0) {
            try {
                $synced = moodle_catalogue_sync_helper::ensure_unit_section($courseid, $name);
                $sectionid = (int)$synced['sectionid'];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'errors' => [[
                        'field' => 'sectionid',
                        'message' => 'Could not sync Moodle section: ' . $e->getMessage(),
                    ]],
                ];
            }
        }

        $errors = self::validate_refs(
            $code, $name, $programmeid, $curriculumid, $yearofstudy, $semestersequence,
            $courseid, $sectionid, $credithours, $unittype, $status, $id, false
        );
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row->code = $code;
        $row->name = $name;
        if (array_key_exists('description', $input)) {
            $row->description = (string)$input['description'];
        }
        $row->programmeid = $programmeid;
        $row->curriculumid = $curriculumid;
        $row->yearofstudy = $yearofstudy;
        $row->semestersequence = $semestersequence;
        $row->courseid = $courseid;
        $row->sectionid = $sectionid;
        $row->credithours = $credithours;
        $row->unittype = $unittype;
        $row->status = $status;
        if (array_key_exists('isrequired', $input)) {
            $row->isrequired = !empty($input['isrequired']) ? 1 : 0;
        }
        $row->timemodified = time();
        $row->modifiedby = $userid;
        $DB->update_record('local_btiportal_course_unit', $row);

        curriculum_unit_helper::ensure_assignment_for_unit((int)$row->id);

        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Update only the Moodle course/section mapping.
     * Does not rewrite academic identity and does not create or delete Moodle courses.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function update_moodle_mapping(int $id, int $courseid, int $sectionid, int $userid): array {
        global $DB;

        $row = $DB->get_record('local_btiportal_course_unit', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course unit not found']]];
        }
        if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
            return ['ok' => false, 'errors' => [['field' => 'courseid', 'message' => 'Moodle course not found']]];
        }
        $section = $sectionid > 0
            ? $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid])
            : false;
        if (!$section) {
            return ['ok' => false, 'errors' => [['field' => 'sectionid', 'message' => 'Moodle section not found for this course']]];
        }

        $row->courseid = $courseid;
        $row->sectionid = $sectionid;
        $row->timemodified = time();
        $row->modifiedby = $userid;
        $DB->update_record('local_btiportal_course_unit', $row);

        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Soft-archive only — does not touch Moodle sections.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function archive(int $id, int $userid): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course unit not found']]];
        }
        $row->status = 'archived';
        $row->timemodified = time();
        $row->modifiedby = $userid;
        $DB->update_record('local_btiportal_course_unit', $row);
        return ['ok' => true, 'record' => self::format($row)];
    }

    /**
     * Auto-archive academic units when a Moodle section is deleted.
     * Additive side-effect for existing delete_course_unit flow; does not change its return shape.
     */
    public static function archive_by_section(int $courseid, int $sectionid): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_btiportal_course_unit')) {
            return 0;
        }
        $rows = $DB->get_records('local_btiportal_course_unit', [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ]);
        $count = 0;
        $now = time();
        foreach ($rows as $row) {
            if ((string)$row->status === 'archived') {
                continue;
            }
            $row->status = 'archived';
            $row->timemodified = $now;
            $DB->update_record('local_btiportal_course_unit', $row);
            $count++;
        }
        return $count;
    }

    /**
     * When a Moodle course is deleted, keep Course Unit catalogue identity.
     * Clear the Moodle mapping only. Do not delete units, teachers, or curriculum relationships.
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;
        if ($courseid <= 1 || !$DB->get_manager()->table_exists('local_btiportal_course_unit')) {
            return;
        }
        $units = $DB->get_records('local_btiportal_course_unit', ['courseid' => $courseid]);
        $now = time();
        foreach ($units as $unit) {
            $unit->courseid = 0;
            $unit->sectionid = 0;
            if (property_exists($unit, 'moodleverifiedat')) {
                $unit->moodleverifiedat = 0;
            }
            $unit->timemodified = $now;
            $DB->update_record('local_btiportal_course_unit', $unit);
        }
    }

    public static function get(int $id): ?array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit', ['id' => $id]);
        return $row ? self::format($row) : null;
    }

    /** @return list<array> */
    public static function list_by_moodle_course(int $courseid, ?string $status = null): array {
        global $DB;
        $params = ['courseid' => $courseid];
        $where = 'courseid = :courseid';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        $rows = $DB->get_records_select(
            'local_btiportal_course_unit',
            $where,
            $params,
            'yearofstudy ASC, semestersequence ASC, code ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format($row);
        }
        return $out;
    }

    /**
     * Whole-catalogue listing for the course units admin page.
     *
     * @return list<array>
     */
    public static function list_all(?string $status = null, ?int $programmeid = null, ?int $curriculumid = null): array {
        global $DB;
        $params = [];
        $where = '1=1';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = strtolower($status);
        }
        if ($programmeid !== null && $programmeid > 0) {
            $where .= ' AND programmeid = :programmeid';
            $params['programmeid'] = $programmeid;
        }
        if ($curriculumid !== null && $curriculumid > 0) {
            $where .= ' AND curriculumid = :curriculumid';
            $params['curriculumid'] = $curriculumid;
        }
        $rows = $DB->get_records_select(
            'local_btiportal_course_unit',
            $where,
            $params,
            'programmeid ASC, curriculumid ASC, yearofstudy ASC, semestersequence ASC, code ASC',
            '*',
            0,
            academic_constants::LIST_LIMIT
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format($row);
        }
        return $out;
    }

    /**
     * @return list<array{field:string,message:string}>
     */
    private static function validate_refs(
        string $code,
        string $name,
        int $programmeid,
        int $curriculumid,
        int $yearofstudy,
        int $semestersequence,
        int $courseid,
        int $sectionid,
        float $credithours,
        string $unittype,
        string $status,
        ?int $excludeid,
        bool $allowmissingmoodle = false
    ): array {
        global $DB;
        $errors = [];

        if ($code === '') {
            $errors[] = ['field' => 'code', 'message' => 'Course unit code is required'];
        }
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Course unit name is required'];
        }
        if ($programmeid <= 0 || !$DB->record_exists('local_btiportal_programme', ['id' => $programmeid])) {
            $errors[] = ['field' => 'programmeid', 'message' => 'Programme not found'];
        }
        $curriculum = $curriculumid > 0
            ? $DB->get_record('local_btiportal_curriculum', ['id' => $curriculumid])
            : false;
        if (!$curriculum) {
            $errors[] = ['field' => 'curriculumid', 'message' => 'Curriculum not found'];
        } else if ($programmeid > 0 && (int)$curriculum->programmeid !== $programmeid) {
            $errors[] = ['field' => 'programmeid', 'message' => 'Programme does not match curriculum'];
        }
        if ($yearofstudy <= 0) {
            $errors[] = ['field' => 'yearofstudy', 'message' => 'yearofstudy must be > 0'];
        }
        if ($semestersequence <= 0) {
            $errors[] = ['field' => 'semestersequence', 'message' => 'semestersequence must be > 0'];
        }
        if ($credithours <= 0) {
            $errors[] = ['field' => 'credithours', 'message' => 'credithours must be > 0'];
        }
        if (!in_array($unittype, academic_constants::UNIT_TYPES, true)) {
            $errors[] = ['field' => 'unittype', 'message' => 'Invalid unit type'];
        }
        if (!in_array($status, academic_constants::UNIT_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'message' => 'Invalid unit status'];
        }

        if (!$allowmissingmoodle) {
            if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
                $errors[] = ['field' => 'courseid', 'message' => 'Moodle course not found'];
            }
            $section = ($sectionid > 0 && $courseid > 0)
                ? $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid])
                : false;
            if (!$section) {
                $errors[] = ['field' => 'sectionid', 'message' => 'Moodle section not found for this course'];
            } else if ((int)$section->section <= 0) {
                $errors[] = ['field' => 'sectionid', 'message' => 'The General section cannot be mapped as a Course Unit'];
            }

            if ($curriculumid > 0 && $courseid > 0 && $sectionid > 0) {
                $dup = $DB->get_record('local_btiportal_course_unit', [
                    'curriculumid' => $curriculumid,
                    'courseid' => $courseid,
                    'sectionid' => $sectionid,
                ]);
                if ($dup && ($excludeid === null || (int)$dup->id !== $excludeid)) {
                    $errors[] = [
                        'field' => 'sectionid',
                        'message' => 'This Moodle section is already mapped in this curriculum',
                    ];
                }
            }
        }

        if ($code !== '') {
            $existingcodes = $DB->get_records('local_btiportal_course_unit', null, '', 'id,code,curriculumid');
            foreach ($existingcodes as $existing) {
                if ($excludeid !== null && (int)$existing->id === $excludeid) {
                    continue;
                }
                if (strcasecmp((string)$existing->code, $code) !== 0) {
                    continue;
                }
                if ((int)$existing->curriculumid === $curriculumid && $curriculumid > 0) {
                    $errors[] = ['field' => 'code', 'message' => 'The unit code already exists within this curriculum'];
                } else {
                    $errors[] = ['field' => 'code', 'message' => 'The unit code already exists'];
                }
                break;
            }
        }

        return $errors;
    }

    public static function format(\stdClass $row): array {
        global $DB;

        $courseid = (int)$row->courseid;
        $moodlecourseshortname = '';
        $moodlecoursefullname = '';
        $moodlecoursevisible = 0;
        $mappingstatus = 'not_connected';
        if ($courseid <= 1) {
            $mappingstatus = 'not_connected';
        } else {
            $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,visible');
            if ($course) {
                $moodlecourseshortname = (string)$course->shortname;
                $moodlecoursefullname = (string)$course->fullname;
                $moodlecoursevisible = (int)$course->visible;
                $mappingstatus = ((int)$course->visible === 1) ? 'connected' : 'unpublished_moodle_course';
            } else {
                $mappingstatus = 'missing_moodle_course';
            }
        }

        return [
            'id' => (int)$row->id,
            'code' => (string)$row->code,
            'name' => (string)$row->name,
            'description' => (string)($row->description ?? ''),
            'programmeid' => (int)$row->programmeid,
            'curriculumid' => (int)$row->curriculumid,
            'yearofstudy' => (int)$row->yearofstudy,
            'semestersequence' => (int)$row->semestersequence,
            'courseid' => $courseid,
            'sectionid' => (int)$row->sectionid,
            'credithours' => (float)$row->credithours,
            'unittype' => (string)$row->unittype,
            'status' => (string)$row->status,
            'isrequired' => (int)$row->isrequired,
            'imageurl' => (string)($row->imageurl ?? ''),
            'moodlecourseshortname' => $moodlecourseshortname,
            'moodlecoursefullname' => $moodlecoursefullname,
            'moodlecoursevisible' => $moodlecoursevisible,
            'mappingstatus' => $mappingstatus,
            'moodleverifiedat' => isset($row->moodleverifiedat) ? (int)$row->moodleverifiedat : 0,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'createdby' => (int)$row->createdby,
            'modifiedby' => (int)$row->modifiedby,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'code' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'description' => new \core_external\external_value(PARAM_RAW, 'description'),
            'programmeid' => new \core_external\external_value(PARAM_INT, 'programmeid'),
            'curriculumid' => new \core_external\external_value(PARAM_INT, 'curriculumid'),
            'yearofstudy' => new \core_external\external_value(PARAM_INT, 'yearofstudy'),
            'semestersequence' => new \core_external\external_value(PARAM_INT, 'semestersequence'),
            'courseid' => new \core_external\external_value(PARAM_INT, 'courseid'),
            'sectionid' => new \core_external\external_value(PARAM_INT, 'sectionid'),
            'credithours' => new \core_external\external_value(PARAM_FLOAT, 'credithours'),
            'unittype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'unittype'),
            'status' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'status'),
            'isrequired' => new \core_external\external_value(PARAM_INT, 'isrequired'),
            'imageurl' => new \core_external\external_value(PARAM_TEXT, 'imageurl', VALUE_OPTIONAL),
            'moodlecourseshortname' => new \core_external\external_value(PARAM_TEXT, 'Moodle shortname', VALUE_OPTIONAL),
            'moodlecoursefullname' => new \core_external\external_value(PARAM_TEXT, 'Moodle fullname', VALUE_OPTIONAL),
            'moodlecoursevisible' => new \core_external\external_value(PARAM_INT, 'Moodle visibility', VALUE_OPTIONAL),
            'mappingstatus' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'connected|not_connected|missing_moodle_course|unpublished_moodle_course', VALUE_OPTIONAL),
            'moodleverifiedat' => new \core_external\external_value(PARAM_INT, 'Last successful Moodle verify timestamp', VALUE_OPTIONAL),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
            'timemodified' => new \core_external\external_value(PARAM_INT, 'timemodified'),
            'createdby' => new \core_external\external_value(PARAM_INT, 'createdby'),
            'modifiedby' => new \core_external\external_value(PARAM_INT, 'modifiedby'),
        ];
    }

    /**
     * Archive a catalogue Course Unit. Never deletes the Moodle course.
     * Curriculum relationships are detached first; the unit row is kept as archived.
     *
     * @return array{ok:bool,record?:array,errors?:list}
     */
    public static function delete(int $id): array {
        global $DB;
        $row = $DB->get_record('local_btiportal_course_unit', ['id' => $id]);
        if (!$row) {
            return ['ok' => false, 'errors' => [['field' => 'id', 'message' => 'Course unit not found']]];
        }
        if ($DB->get_manager()->table_exists(curriculum_unit_helper::TABLE)) {
            $DB->delete_records(curriculum_unit_helper::TABLE, ['courseunitid' => $id]);
        }
        $row->status = 'archived';
        $row->timemodified = time();
        $DB->update_record('local_btiportal_course_unit', $row);
        return ['ok' => true, 'record' => self::format($row)];
    }
}
