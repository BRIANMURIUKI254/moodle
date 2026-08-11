<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Registrar personal details stored as Moodle custom profile fields (not student_profile).
 */
class registry_profile_helper {
    public const CATEGORY_SHORTNAME = 'bti_student_registry';
    public const CATEGORY_NAME = 'Student Registry';

    /** @var array<string,array{name:string,datatype:string,param1?:string}> */
    private const FIELDS = [
        'bti_dob' => ['name' => 'Date of birth', 'datatype' => 'text'],
        'bti_gender' => [
            'name' => 'Gender',
            'datatype' => 'menu',
            'param1' => "male\nfemale\nother\nprefer_not_to_say",
        ],
        'bti_nationality' => ['name' => 'Nationality', 'datatype' => 'text'],
        'bti_nationalid' => ['name' => 'National ID', 'datatype' => 'text'],
        'bti_guardian_name' => ['name' => 'Guardian name', 'datatype' => 'text'],
        'bti_guardian_phone' => ['name' => 'Guardian phone', 'datatype' => 'text'],
        'bti_guardian_email' => ['name' => 'Guardian email', 'datatype' => 'text'],
        'bti_emergency_contact' => ['name' => 'Emergency contact', 'datatype' => 'text'],
    ];

    /**
     * @return list<string>
     */
    public static function field_shortnames(): array {
        return array_keys(self::FIELDS);
    }

    /**
     * Ensure the Student Registry category and its profile fields exist.
     */
    public static function ensure_fields(): void {
        global $DB;

        $category = null;
        $columns = $DB->get_columns('user_info_category');
        // Moodle core categories have no shortname; honour it when present (custom schemas).
        if (!empty($columns['shortname'])) {
            $category = $DB->get_record('user_info_category', ['shortname' => self::CATEGORY_SHORTNAME]);
        }
        if (!$category) {
            $category = $DB->get_record('user_info_category', ['name' => self::CATEGORY_NAME]);
        }
        if (!$category) {
            $maxsort = (int)$DB->get_field_sql('SELECT MAX(sortorder) FROM {user_info_category}');
            $category = (object)[
                'name' => self::CATEGORY_NAME,
                'sortorder' => $maxsort + 1,
            ];
            if (!empty($columns['shortname'])) {
                $category->shortname = self::CATEGORY_SHORTNAME;
            }
            $category->id = $DB->insert_record('user_info_category', $category);
        } else if (!empty($columns['shortname']) && empty($category->shortname)) {
            $category->shortname = self::CATEGORY_SHORTNAME;
            $DB->update_record('user_info_category', $category);
        }

        $sort = 1;
        foreach (self::FIELDS as $shortname => $meta) {
            $existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);
            if ($existing) {
                if ((int)$existing->categoryid !== (int)$category->id) {
                    $existing->categoryid = (int)$category->id;
                    $DB->update_record('user_info_field', $existing);
                }
                $sort++;
                continue;
            }

            $field = (object)[
                'shortname' => $shortname,
                'name' => $meta['name'],
                'datatype' => $meta['datatype'],
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
                'categoryid' => (int)$category->id,
                'sortorder' => $sort,
                'required' => 0,
                'locked' => 0,
                'visible' => 2,
                'forceunique' => 0,
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_HTML,
                'param1' => $meta['param1'] ?? ($meta['datatype'] === 'text' ? '30' : ''),
                'param2' => $meta['datatype'] === 'text' ? '2048' : '',
                'param3' => '',
                'param4' => '',
                'param5' => '',
            ];
            $DB->insert_record('user_info_field', $field);
            $sort++;
        }
    }

    /**
     * Write registry personal details for a Moodle user.
     *
     * @param array<string,string> $data Keys are field shortnames (bti_dob, …).
     */
    public static function save_for_user(int $userid, array $data): void {
        global $CFG, $DB;

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('invaliduser', 'error');
        }

        self::ensure_fields();
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        profile_load_data($user);

        $wrote = false;
        foreach (self::field_shortnames() as $shortname) {
            if (!array_key_exists($shortname, $data)) {
                continue;
            }
            $value = trim((string)$data[$shortname]);
            $user->{'profile_field_' . $shortname} = $value;
            $wrote = true;
        }

        if ($wrote) {
            profile_save_data($user);
        }

        // Authoritative upsert so empty clears and free-text menu values persist.
        foreach (self::field_shortnames() as $shortname) {
            if (!array_key_exists($shortname, $data)) {
                continue;
            }
            $value = trim((string)$data[$shortname]);
            $field = $DB->get_record('user_info_field', ['shortname' => $shortname], '*', MUST_EXIST);
            $existing = $DB->get_record('user_info_data', [
                'userid' => $userid,
                'fieldid' => (int)$field->id,
            ]);
            if ($existing) {
                $existing->data = $value;
                $existing->dataformat = FORMAT_HTML;
                $DB->update_record('user_info_data', $existing);
            } else {
                $DB->insert_record('user_info_data', (object)[
                    'userid' => $userid,
                    'fieldid' => (int)$field->id,
                    'data' => $value,
                    'dataformat' => FORMAT_HTML,
                ]);
            }
        }
    }

    /**
     * @return array<string,string>
     */
    public static function get_for_user(int $userid): array {
        global $CFG, $DB;

        self::ensure_fields();
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $out = [];
        foreach (self::field_shortnames() as $shortname) {
            $out[$shortname] = '';
        }

        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return $out;
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        profile_load_data($user);

        foreach (self::field_shortnames() as $shortname) {
            $prop = 'profile_field_' . $shortname;
            if (isset($user->{$prop}) && $user->{$prop} !== null && $user->{$prop} !== '') {
                $out[$shortname] = (string)$user->{$prop};
                continue;
            }
            $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
            if (!$field) {
                continue;
            }
            $data = $DB->get_record('user_info_data', [
                'userid' => $userid,
                'fieldid' => (int)$field->id,
            ]);
            if ($data) {
                $out[$shortname] = (string)$data->data;
            }
        }

        return $out;
    }

    public static function structure_keys(): array {
        $keys = [];
        foreach (self::field_shortnames() as $shortname) {
            $keys[$shortname] = new \core_external\external_value(PARAM_TEXT, $shortname, VALUE_OPTIONAL);
        }
        return $keys;
    }
}
