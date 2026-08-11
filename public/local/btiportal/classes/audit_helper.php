<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Append-only operational audit log.
 *
 * Never stores passwords, tokens, or secrets. Records are not editable or
 * deletable through normal portal APIs.
 */
class audit_helper {
    /**
     * @param array{
     *   action:string,
     *   entitytype?:string,
     *   entityid?:int,
     *   departmentid?:int,
     *   before?:?array,
     *   after?:?array,
     *   result?:string,
     *   failurereason?:string,
     *   sourcemodule?:string,
     *   actorrole?:string
     * } $input
     */
    public static function log(array $input, int $actorid = 0): int {
        global $DB, $USER;
        $actorid = $actorid > 0 ? $actorid : (int)$USER->id;
        $now = time();

        $before = self::sanitize_state($input['before'] ?? null);
        $after = self::sanitize_state($input['after'] ?? null);

        return (int)$DB->insert_record('local_btiportal_op_audit', (object)[
            'actorid' => $actorid,
            'actorrole' => substr((string)($input['actorrole'] ?? self::infer_actor_role($actorid)), 0, 64),
            'action' => substr((string)($input['action'] ?? ''), 0, 64),
            'entitytype' => substr((string)($input['entitytype'] ?? ''), 0, 64),
            'entityid' => (int)($input['entityid'] ?? 0),
            'departmentid' => (int)($input['departmentid'] ?? 0),
            'beforestate' => $before !== null ? json_encode($before) : null,
            'afterstate' => $after !== null ? json_encode($after) : null,
            'result' => substr((string)($input['result'] ?? 'success'), 0, 32),
            'failurereason' => substr((string)($input['failurereason'] ?? ''), 0, 255),
            'sourcemodule' => substr((string)($input['sourcemodule'] ?? 'local_btiportal'), 0, 64),
            'timecreated' => $now,
        ]);
    }

    /**
     * @return list<array>
     */
    public static function list(
        int $userid,
        ?int $departmentid = null,
        ?string $action = null,
        ?string $entitytype = null,
        int $limit = 200
    ): array {
        global $DB;

        $scope = department_auth_helper::resolve_scope($userid, $departmentid);
        if (!$scope['ok']) {
            return [];
        }

        $params = [];
        $where = '1=1';

        if (!$scope['university']) {
            $ids = $scope['departmentids'];
            if (!$ids) {
                return [];
            }
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'd');
            $where .= " AND departmentid {$insql}";
            $params = array_merge($params, $inparams);
        } else if ($departmentid !== null && $departmentid > 0) {
            $where .= ' AND departmentid = :departmentid';
            $params['departmentid'] = $departmentid;
        }

        if ($action !== null && $action !== '') {
            $where .= ' AND action = :action';
            $params['action'] = $action;
        }
        if ($entitytype !== null && $entitytype !== '') {
            $where .= ' AND entitytype = :entitytype';
            $params['entitytype'] = $entitytype;
        }

        $limit = max(1, min(500, $limit));
        $rows = $DB->get_records_select(
            'local_btiportal_op_audit',
            $where,
            $params,
            'timecreated DESC, id DESC',
            '*',
            0,
            $limit
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::format($row);
        }
        return $out;
    }

    public static function format(\stdClass $row): array {
        return [
            'id' => (int)$row->id,
            'actorid' => (int)$row->actorid,
            'actorrole' => (string)$row->actorrole,
            'action' => (string)$row->action,
            'entitytype' => (string)$row->entitytype,
            'entityid' => (int)$row->entityid,
            'departmentid' => (int)$row->departmentid,
            'beforestate' => (string)($row->beforestate ?? ''),
            'afterstate' => (string)($row->afterstate ?? ''),
            'result' => (string)$row->result,
            'failurereason' => (string)($row->failurereason ?? ''),
            'sourcemodule' => (string)$row->sourcemodule,
            'timecreated' => (int)$row->timecreated,
        ];
    }

    public static function structure_keys(): array {
        return [
            'id' => new \core_external\external_value(PARAM_INT, 'id'),
            'actorid' => new \core_external\external_value(PARAM_INT, 'actorid'),
            'actorrole' => new \core_external\external_value(PARAM_TEXT, 'actorrole'),
            'action' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'action'),
            'entitytype' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'entitytype'),
            'entityid' => new \core_external\external_value(PARAM_INT, 'entityid'),
            'departmentid' => new \core_external\external_value(PARAM_INT, 'departmentid'),
            'beforestate' => new \core_external\external_value(PARAM_RAW, 'beforestate'),
            'afterstate' => new \core_external\external_value(PARAM_RAW, 'afterstate'),
            'result' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'result'),
            'failurereason' => new \core_external\external_value(PARAM_TEXT, 'failurereason'),
            'sourcemodule' => new \core_external\external_value(PARAM_TEXT, 'sourcemodule'),
            'timecreated' => new \core_external\external_value(PARAM_INT, 'timecreated'),
        ];
    }

    private static function infer_actor_role(int $userid): string {
        if (department_auth_helper::is_university_admin($userid)) {
            return 'admin';
        }
        if (department_auth_helper::is_department_leader($userid)) {
            return 'department_leader';
        }
        return 'user';
    }

    /**
     * @param mixed $state
     * @return mixed
     */
    private static function sanitize_state($state) {
        if ($state === null) {
            return null;
        }
        if (!is_array($state)) {
            return $state;
        }
        $blocked = ['password', 'token', 'secret', 'apikey', 'sessionkey', 'wstoken'];
        $clean = [];
        foreach ($state as $key => $value) {
            $lk = strtolower((string)$key);
            $skip = false;
            foreach ($blocked as $b) {
                if (strpos($lk, $b) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::sanitize_state($value) : $value;
        }
        return $clean;
    }
}
