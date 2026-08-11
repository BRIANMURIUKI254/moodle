<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Department-scoped operational authorization.
 *
 * University-wide admin access uses local/btiportal:manageacademic (or site admin).
 * Department Leaders retain their Moodle lecturer identity; operational access is
 * granted only for departments where they have an active leadership assignment.
 * A capability alone never grants access to every department.
 */
class department_auth_helper {
    public const LEADERSHIP_ROLES = ['head', 'deputy_head'];

    /**
     * True when the user may manage university-wide operational records.
     */
    public static function is_university_admin(?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        if ($userid <= 0) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        $context = \context_system::instance();
        return has_capability('local/btiportal:manageacademic', $context, $userid);
    }

    /**
     * Active Department Leader assignments for a Moodle user.
     *
     * Leadership is department-specific via isdepartmentleader (preferred) or
     * membershiprole head/deputy_head on an active assignment within its date window.
     *
     * @return list<array>
     */
    public static function get_leadership_departments(int $userid): array {
        global $DB;
        if ($userid <= 0) {
            return [];
        }
        $now = time();
        $rows = $DB->get_records_select(
            'local_btiportal_lecturer_dept',
            "userid = :userid AND status = 'active'
             AND (
                COALESCE(isdepartmentleader, 0) = 1
                OR membershiprole IN ('head', 'deputy_head')
             )
             AND (COALESCE(timestart, 0) = 0 OR timestart <= :now1)
             AND (COALESCE(timeend, 0) = 0 OR timeend >= :now2)",
            ['userid' => $userid, 'now1' => $now, 'now2' => $now],
            'isprimary DESC, departmentid ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $dept = $DB->get_record('local_btiportal_department', ['id' => (int)$row->departmentid]);
            if (!$dept || (string)$dept->status !== 'active') {
                continue;
            }
            $out[] = [
                'assignmentid' => (int)$row->id,
                'departmentid' => (int)$row->departmentid,
                'departmentcode' => (string)$dept->code,
                'departmentname' => (string)$dept->name,
                'schoolid' => (int)$dept->schoolid,
                'membershiprole' => (string)$row->membershiprole,
                'isdepartmentleader' => (
                    (int)($row->isdepartmentleader ?? 0) === 1
                    || in_array((string)$row->membershiprole, self::LEADERSHIP_ROLES, true)
                ) ? 1 : 0,
                'isprimary' => (int)$row->isprimary,
                'timestart' => (int)($row->timestart ?? 0),
                'timeend' => (int)($row->timeend ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @return list<int>
     */
    public static function get_authorized_department_ids(int $userid): array {
        if (self::is_university_admin($userid)) {
            return []; // Empty means unrestricted for admins; callers must treat specially.
        }
        $ids = [];
        foreach (self::get_leadership_departments($userid) as $row) {
            $ids[] = (int)$row['departmentid'];
        }
        return array_values(array_unique($ids));
    }

    public static function is_department_leader(int $userid, ?int $departmentid = null): bool {
        $depts = self::get_leadership_departments($userid);
        if (!$depts) {
            return false;
        }
        if ($departmentid === null || $departmentid <= 0) {
            return true;
        }
        foreach ($depts as $row) {
            if ((int)$row['departmentid'] === $departmentid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve which department IDs the caller may use for a request.
     *
     * @return array{ok:bool,university:bool,departmentids:list<int>,errors?:list}
     */
    public static function resolve_scope(int $userid, ?int $requesteddepartmentid = null): array {
        if (self::is_university_admin($userid)) {
            if ($requesteddepartmentid !== null && $requesteddepartmentid > 0) {
                if (!department_helper::exists($requesteddepartmentid)) {
                    return [
                        'ok' => false,
                        'university' => true,
                        'departmentids' => [],
                        'errors' => [['field' => 'departmentid', 'message' => 'Department not found']],
                    ];
                }
                return [
                    'ok' => true,
                    'university' => true,
                    'departmentids' => [$requesteddepartmentid],
                ];
            }
            return ['ok' => true, 'university' => true, 'departmentids' => []];
        }

        $authorized = self::get_authorized_department_ids($userid);
        if (!$authorized) {
            return [
                'ok' => false,
                'university' => false,
                'departmentids' => [],
                'errors' => [['field' => '', 'message' => 'Department Leader assignment required']],
            ];
        }

        if ($requesteddepartmentid !== null && $requesteddepartmentid > 0) {
            if (!in_array($requesteddepartmentid, $authorized, true)) {
                return [
                    'ok' => false,
                    'university' => false,
                    'departmentids' => [],
                    'errors' => [['field' => 'departmentid', 'message' => 'Department is outside your authorized scope']],
                ];
            }
            return [
                'ok' => true,
                'university' => false,
                'departmentids' => [$requesteddepartmentid],
            ];
        }

        return [
            'ok' => true,
            'university' => false,
            'departmentids' => $authorized,
        ];
    }

    /**
     * Require university admin OR department leadership (optionally for a specific dept).
     *
     * Capability checks are paired with leadership, but Moodle lecturers usually hold
     * editingteacher at course context only. System-context require_capability would
     * deny every Department Leader even with a valid leadership assignment. Therefore:
     * - university admins: always allowed (capability/manageacademic already checked)
     * - active Department Leaders: allowed for their authorized departments via resolve_scope
     * - everyone else: must hold the named capability at system context
     *
     * @throws \moodle_exception
     * @return array{ok:bool,university:bool,departmentids:list<int>}
     */
    public static function require_operational_access(
        ?int $departmentid = null,
        string $capability = '',
        bool $requireexactdepartment = false
    ): array {
        global $USER;
        $context = \context_system::instance();
        \core_external\external_api::validate_context($context);

        $userid = (int)$USER->id;
        $isadmin = self::is_university_admin($userid);
        $isleader = self::is_department_leader($userid, $departmentid);

        if ($capability !== '' && !$isadmin && !$isleader) {
            require_capability($capability, $context);
        }

        if ($requireexactdepartment && ($departmentid === null || $departmentid <= 0)) {
            throw new \moodle_exception('invalidparameter', 'error', '', null,
                'A specific departmentid is required for this action');
        }

        $scope = self::resolve_scope($userid, $departmentid);
        if (!$scope['ok']) {
            $msg = $scope['errors'][0]['message'] ?? 'Not authorised';
            throw new \moodle_exception('nopermissions', 'error', '', $msg);
        }
        return $scope;
    }

    /**
     * Soft check used by list endpoints that should return empty rather than throw.
     */
    public static function try_operational_access(?int $departmentid = null): ?array {
        global $USER;
        try {
            return self::require_operational_access($departmentid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Structure keys for leadership rows returned in user context.
     */
    public static function leadership_structure_keys(): array {
        return [
            'assignmentid' => new \core_external\external_value(PARAM_INT, 'lecturer_dept id'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'departmentcode' => new \core_external\external_value(PARAM_TEXT, 'code'),
            'departmentname' => new \core_external\external_value(PARAM_TEXT, 'name'),
            'schoolid' => new \core_external\external_value(PARAM_INT, 'schoolid'),
            'membershiprole' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'membershiprole'),
            'isdepartmentleader' => new \core_external\external_value(PARAM_INT, 'isdepartmentleader'),
            'isprimary' => new \core_external\external_value(PARAM_INT, 'isprimary'),
            'timestart' => new \core_external\external_value(PARAM_INT, 'timestart'),
            'timeend' => new \core_external\external_value(PARAM_INT, 'timeend'),
        ];
    }
}
