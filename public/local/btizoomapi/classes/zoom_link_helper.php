<?php
namespace local_btizoomapi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/zoom/lib.php');
require_once($CFG->dirroot . '/mod/zoom/locallib.php');

/**
 * Resolve (and optionally create) Zoom join URLs scoped to a Moodle course / section.
 */
class zoom_link_helper {
    /**
     * @return array{found:bool,joinurl:string,name:string,created?:bool,cmid?:int}
     */
    public static function find(int $courseid, int $sectionid = 0): array {
        global $DB;

        if ($courseid <= 1) {
            return self::empty_result();
        }

        if ($sectionid > 0) {
            // Prefer an explicitly linked Zoom CM for this portal unit (course + section).
            if ($DB->get_manager()->table_exists('local_btiportal_unit')) {
                $unit = $DB->get_record('local_btiportal_unit', [
                    'courseid' => $courseid,
                    'sectionid' => $sectionid,
                ], 'id, zoomcmid', IGNORE_MISSING);
                $zoomcmid = $unit ? (int)($unit->zoomcmid ?? 0) : 0;
                if ($zoomcmid > 0) {
                    $fromcm = self::zoom_from_cmid($zoomcmid, $courseid);
                    if ($fromcm) {
                        return $fromcm;
                    }
                }
            }

            $row = self::newest_zoom_in_section($courseid, $sectionid);
            if ($row) {
                return [
                    'found' => true,
                    'joinurl' => (string) $row->join_url,
                    'name' => (string) $row->name,
                    'created' => false,
                    'cmid' => (int) $row->cmid,
                ];
            }

            // Section-scoped: do not fall back to another section's meeting.
            return self::empty_result();
        }

        $candidates = $DB->get_records_select(
            'zoom',
            'course = ? AND join_url IS NOT NULL AND join_url <> ?',
            [$courseid, ''],
            'timemodified DESC, id DESC',
            'id, name, join_url, meeting_id, timemodified',
            0,
            1
        );
        $zoom = $candidates ? reset($candidates) : false;
        if (!$zoom || empty($zoom->join_url)) {
            return self::empty_result();
        }

        return [
            'found' => true,
            'joinurl' => (string) $zoom->join_url,
            'name' => (string) $zoom->name,
            'created' => false,
            'cmid' => 0,
        ];
    }

    /**
     * Find an existing section Zoom link, or create a recurring (no fixed time) meeting in that section.
     *
     * @return array{found:bool,joinurl:string,name:string,created:bool,cmid:int}
     */
    public static function ensure(int $courseid, int $sectionid, string $displayname = ''): array {
        global $DB, $USER;

        if ($courseid <= 1 || $sectionid <= 0) {
            return self::empty_result();
        }

        $existing = self::find($courseid, $sectionid);
        if (!empty($existing['found']) && !empty($existing['joinurl'])) {
            if (!empty($existing['cmid'])) {
                self::bind_zoomcmid($courseid, $sectionid, (int) $existing['cmid'], (int) $USER->id);
            }
            $existing['created'] = false;
            return $existing;
        }

        $section = $DB->get_record('course_sections', [
            'id' => $sectionid,
            'course' => $courseid,
        ], '*', MUST_EXIST);

        if ((int) $section->section <= 0) {
            throw new \moodle_exception('error', 'error', '', null,
                'Cannot create a Zoom meeting in the General section — map a real course unit topic.');
        }

        if (!$DB->record_exists('modules', ['name' => 'zoom'])) {
            throw new \moodle_exception('error', 'error', '', null, 'Zoom activity module is not installed');
        }

        $course = get_course($courseid);
        $module = $DB->get_record('modules', ['name' => 'zoom'], '*', MUST_EXIST);
        $config = get_config('zoom');

        $name = trim($displayname);
        if ($name === '') {
            $name = trim((string) ($section->name ?? ''));
        }
        if ($name === '') {
            $name = 'Course unit meeting';
        }
        if (\core_text::strlen($name) > 300) {
            $name = \core_text::substr($name, 0, 300);
        }

        $hostid = zoom_get_meeting_host_user_id(true);
        if (empty($hostid)) {
            throw new \moodle_exception('zoomerr_usernotfound', 'mod_zoom', '', get_config('zoom', 'zoomurl'));
        }

        $data = new \stdClass();
        $data->course = $courseid;
        $data->modulename = 'zoom';
        $data->module = (int) $module->id;
        $data->name = $name;
        $data->intro = '<p>Auto-generated Zoom meeting for this course unit.</p>';
        $data->introformat = FORMAT_HTML;
        // add_moduleinfo expects section *number*; we re-assert section id afterwards.
        $data->section = (int) $section->section;
        $data->visible = 1;
        $data->visibleoncoursepage = 1;
        $data->cmidnumber = '';
        $data->groupmode = 0;
        $data->groupingid = 0;
        $data->completion = COMPLETION_TRACKING_NONE;
        $data->completionexpected = 0;
        $data->showdescription = 0;
        $data->availabilityconditionsjson = '';
        $data->grade = 0;
        $data->grading_method = !empty($config->gradingmethod) ? $config->gradingmethod : 'entry';

        $data->host_id = $hostid;
        $data->recurring = 1;
        $data->recurrence_type = ZOOM_RECURRINGTYPE_NOTIME;
        $data->webinar = 0;
        $data->start_time = time() + DAYSECS;
        $data->duration = HOURSECS;
        $data->timezone = \core_date::get_user_timezone();
        $data->requirepasscode = 0;
        $data->meetingcode = '';
        $data->option_jbh = !empty($config->defaultjoinbeforehost) ? 1 : 0;
        $data->option_waiting_room = array_key_exists('defaultwaitingroomoption', (array) $config)
            ? (int) (bool) $config->defaultwaitingroomoption : 1;
        $data->option_mute_upon_entry = array_key_exists('defaultmuteuponentryoption', (array) $config)
            ? (int) (bool) $config->defaultmuteuponentryoption : 1;
        $data->option_host_video = !empty($config->defaulthostvideo) ? 1 : 0;
        $data->option_participants_video = !empty($config->defaultparticipantsvideo) ? 1 : 0;
        $data->option_audio = !empty($config->defaultaudiooption) ? $config->defaultaudiooption : ZOOM_AUDIO_BOTH;
        $data->option_authenticated_users = !empty($config->defaultauthusersoption) ? 1 : 0;
        $data->option_encryption_type = !empty($config->defaultencryptiontypeoption)
            ? $config->defaultencryptiontypeoption : 'enhanced_encryption';
        $data->registration = ZOOM_REGISTRATION_OFF;
        $data->recordings_visible_default = 1;
        $data->show_schedule = array_key_exists('defaultshowschedule', (array) $config)
            ? (int) (bool) $config->defaultshowschedule : 1;
        $data->show_security = array_key_exists('defaultshowsecurity', (array) $config)
            ? (int) (bool) $config->defaultshowsecurity : 1;
        $data->show_media = array_key_exists('defaultshowmedia', (array) $config)
            ? (int) (bool) $config->defaultshowmedia : 1;

        $cm = add_moduleinfo($data, $course);
        $cmid = (int) ($cm->coursemodule ?? 0);
        $instanceid = (int) ($cm->instance ?? 0);
        if ($cmid <= 0 || $instanceid <= 0) {
            throw new \moodle_exception('error', 'error', '', null, 'Failed to create Zoom activity for course unit');
        }

        $cmrec = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        if ((int) $cmrec->section !== (int) $section->id) {
            $DB->set_field('course_modules', 'section', $section->id, ['id' => $cmid]);
            rebuild_course_cache($courseid, true);
        }

        self::bind_zoomcmid($courseid, $sectionid, $cmid, (int) $USER->id);

        $zoom = $DB->get_record('zoom', ['id' => $instanceid], 'id, name, join_url', MUST_EXIST);
        if (empty($zoom->join_url)) {
            throw new \moodle_exception('error', 'error', '', null,
                'Zoom meeting was created but no join URL was returned — check Zoom API configuration.');
        }

        return [
            'found' => true,
            'joinurl' => (string) $zoom->join_url,
            'name' => (string) $zoom->name,
            'created' => true,
            'cmid' => $cmid,
        ];
    }

    /**
     * @return \stdClass|false
     */
    private static function newest_zoom_in_section(int $courseid, int $sectionid) {
        global $DB;

        $sql = "SELECT z.id, z.name, z.join_url, z.timemodified, cm.id AS cmid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'zoom'
                  JOIN {zoom} z ON z.id = cm.instance
                 WHERE cm.course = :courseid
                   AND cm.section = :sectionid
                   AND cm.deletioninprogress = 0
                   AND z.join_url IS NOT NULL
                   AND z.join_url <> :empty
              ORDER BY z.timemodified DESC, z.id DESC";
        return $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'empty' => '',
        ]);
    }

    /**
     * Persist zoomcmid on local_btiportal_unit when that table exists.
     */
    private static function bind_zoomcmid(int $courseid, int $sectionid, int $cmid, int $teacheruserid): void {
        global $DB;

        if ($cmid <= 0 || !$DB->get_manager()->table_exists('local_btiportal_unit')) {
            return;
        }

        $existing = $DB->get_record('local_btiportal_unit', [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
        ], '*', IGNORE_MULTIPLE);

        if ($existing) {
            if ((int) ($existing->zoomcmid ?? 0) !== $cmid) {
                $existing->zoomcmid = $cmid;
                $DB->update_record('local_btiportal_unit', $existing);
            }
            return;
        }

        if ($teacheruserid <= 0) {
            $teacheruserid = 2; // Fallback site admin if WS user is odd.
        }
        $DB->insert_record('local_btiportal_unit', (object) [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'teacheruserid' => $teacheruserid,
            'zoomcmid' => $cmid,
            'timecreated' => time(),
        ]);
    }

    /**
     * @return array{found:bool,joinurl:string,name:string,created:bool,cmid:int}|null
     */
    public static function zoom_from_cmid(int $cmid, int $courseid): ?array {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], '*', IGNORE_MISSING);
        if (!$cm || (int) $cm->deletioninprogress === 1) {
            return null;
        }
        $mod = $DB->get_record('modules', ['id' => (int) $cm->module], 'id, name', IGNORE_MISSING);
        if (!$mod || $mod->name !== 'zoom') {
            return null;
        }
        $zoom = $DB->get_record('zoom', ['id' => (int) $cm->instance], 'id, name, join_url', IGNORE_MISSING);
        if (!$zoom || empty($zoom->join_url)) {
            return null;
        }
        return [
            'found' => true,
            'joinurl' => (string) $zoom->join_url,
            'name' => (string) $zoom->name,
            'created' => false,
            'cmid' => $cmid,
        ];
    }

    /**
     * @return array{found:bool,joinurl:string,name:string,created:bool,cmid:int}
     */
    public static function empty_result(): array {
        return [
            'found' => false,
            'joinurl' => '',
            'name' => '',
            'created' => false,
            'cmid' => 0,
        ];
    }

    public static function result_structure_keys(): array {
        return [
            'found' => new \core_external\external_value(PARAM_BOOL, 'Whether a Zoom meeting with a join URL was found'),
            'joinurl' => new \core_external\external_value(PARAM_RAW, 'Zoom join URL (empty when not found)'),
            'name' => new \core_external\external_value(PARAM_TEXT, 'Zoom activity name (empty when not found)'),
            'created' => new \core_external\external_value(PARAM_BOOL, 'True when a new Zoom activity was created', VALUE_OPTIONAL),
            'cmid' => new \core_external\external_value(PARAM_INT, 'Course module id of the Zoom activity', VALUE_OPTIONAL),
        ];
    }
}
