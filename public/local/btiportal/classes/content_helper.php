<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Announcements, class recordings, and course calendar events for the portal.
 */
class content_helper {
    /**
     * True if the user may publish content for this course (teacher enrolment or unit mapping).
     */
    public static function user_can_manage_course(int $userid, int $courseid): bool {
        global $DB;

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        if (has_capability('moodle/course:update', $context, $userid)
            || has_capability('moodle/calendar:manageentries', $context, $userid)
            || has_capability('moodle/course:manageactivities', $context, $userid)
            || has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
            return true;
        }

        $roles = get_user_roles($context, $userid, true);
        foreach ($roles as $role) {
            $short = $role->shortname ?? '';
            if (in_array($short, ['editingteacher', 'teacher', 'manager', 'coursecreator'], true)) {
                return true;
            }
        }

        // Portal unit teacher mapping (even without Moodle enrolment role).
        if ($DB->record_exists('local_btiportal_unit', [
            'courseid' => $courseid,
            'teacheruserid' => $userid,
        ])) {
            return true;
        }

        // Any unit mapping for this course where the user is a teacher on another
        // section of the same course still counts.
        return $DB->record_exists_select(
            'local_btiportal_unit',
            'courseid = ? AND teacheruserid = ?',
            [$courseid, $userid]
        );
    }

    /**
     * True if the user is enrolled in the course (any role).
     */
    public static function user_can_view_course(int $userid, int $courseid): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if (is_enrolled($context, $userid, '', true)) {
            return true;
        }
        return self::user_can_manage_course($userid, $courseid);
    }

    /**
     * Create a course calendar event without requiring manageentries on the caller
     * (capability already verified via user_can_manage_course).
     */
    public static function create_course_calendar_event(
        int $courseid,
        string $name,
        string $description,
        int $timestart,
        int $timeduration = 0
    ): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $event = new \stdClass();
        $event->name = $name;
        $event->description = [
            'text' => $description,
            'format' => FORMAT_HTML,
        ];
        $event->courseid = $courseid;
        $event->eventtype = 'course';
        $event->timestart = $timestart;
        $event->timeduration = max(0, $timeduration);
        $event->visible = 1;
        $event->modulename = '';
        $event->instance = 0;

        $created = \calendar_event::create($event, false);
        return (object) [
            'id' => (int)$created->id,
            'name' => $created->name,
            'courseid' => (int)$created->courseid,
            'timestart' => (int)$created->timestart,
            'timeduration' => (int)$created->timeduration,
            'eventtype' => $created->eventtype,
        ];
    }

    public static function delete_calendar_events(array $eventids): void {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');
        foreach ($eventids as $eventid) {
            $eventid = (int)$eventid;
            if ($eventid <= 0) {
                continue;
            }
            $event = \calendar_event::load($eventid);
            $event->delete(true);
        }
    }

    public static function publish_announcement(
        int $courseid,
        int $authorid,
        string $title,
        string $body,
        string $scope = 'course',
        int $departmentid = 0
    ): array {
        global $DB;

        $scope = self::normalize_content_scope($scope, $courseid, $departmentid);
        $record = (object) [
            'courseid' => $courseid,
            'authorid' => $authorid,
            'title' => $title,
            'body' => $body,
            'timecreated' => time(),
        ];
        $columns = $DB->get_columns('local_btiportal_announcement');
        if (isset($columns['departmentid'])) {
            $record->departmentid = $departmentid;
        }
        if (isset($columns['scope'])) {
            $record->scope = $scope;
        }
        if (isset($columns['status'])) {
            $record->status = 'published';
        }

        $id = $DB->insert_record('local_btiportal_announcement', $record);

        // Also put on the course/site calendar so enrolled students see it on /calendar.
        if ($courseid <= 1 || $scope === 'university' || $scope === 'department') {
            $event = self::create_site_calendar_event(
                'Announcement: ' . $title,
                $body,
                time(),
                0
            );
        } else {
            $event = self::create_course_calendar_event(
                $courseid,
                'Announcement: ' . $title,
                $body,
                time(),
                0
            );
        }

        $DB->set_field('local_btiportal_announcement', 'eventid', $event->id, ['id' => $id]);

        if (class_exists(audit_helper::class)) {
            audit_helper::log([
                'action' => 'announcement_publish',
                'entitytype' => 'announcement',
                'entityid' => $id,
                'departmentid' => $departmentid,
                'after' => ['scope' => $scope, 'courseid' => $courseid, 'title' => $title],
                'result' => 'success',
                'sourcemodule' => 'content',
            ], $authorid);
        }

        return self::format_announcement($DB->get_record('local_btiportal_announcement', ['id' => $id], '*', MUST_EXIST));
    }

    public static function list_announcements_for_courses(array $courseids, int $limit = 50): array {
        global $DB;
        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        if (!$courseids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'local_btiportal_announcement',
            "courseid {$insql}",
            $params,
            'timecreated DESC',
            '*',
            0,
            $limit
        );
        return array_map([self::class, 'format_announcement'], array_values($records));
    }

    public static function publish_recording(
        int $courseid,
        int $authorid,
        string $title,
        string $type,
        string $sessiondate,
        string $description,
        string $link = '',
        string $filename = '',
        int $sectionid = 0,
        string $unitname = '',
        int $draftitemid = 0
    ): array {
        global $DB, $CFG, $USER;

        $type = $type === 'event' ? 'event' : 'class';
        $record = (object) [
            'courseid' => $courseid,
            'authorid' => $authorid,
            'title' => $title,
            'type' => $type,
            'sessiondate' => $sessiondate,
            'description' => $description,
            'link' => $link,
            'filename' => $filename,
            'timecreated' => time(),
        ];
        $columns = $DB->get_columns('local_btiportal_recording');
        if (isset($columns['sectionid'])) {
            $record->sectionid = $sectionid;
        }
        if (isset($columns['unitname'])) {
            $record->unitname = $unitname;
        }

        $id = $DB->insert_record('local_btiportal_recording', $record);

        // Persist an uploaded draft video into the plugin file area.
        if ($draftitemid > 0) {
            require_once($CFG->libdir . '/filelib.php');
            $context = \context_system::instance();
            $options = [
                'subdirs' => 0,
                'maxfiles' => 1,
                'accepted_types' => ['.mp4', '.webm', '.mov', '.mkv', '.m4v', 'video'],
            ];
            file_save_draft_area_files(
                $draftitemid,
                $context->id,
                'local_btiportal',
                'recording',
                $id,
                $options
            );

            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'local_btiportal', 'recording', $id, 'filename', false);
            $stored = reset($files);
            if ($stored) {
                $url = \moodle_url::make_webservice_pluginfile_url(
                    $context->id,
                    'local_btiportal',
                    'recording',
                    $id,
                    '/',
                    $stored->get_filename()
                );
                $DB->set_field('local_btiportal_recording', 'link', $url->out(false), ['id' => $id]);
                $DB->set_field('local_btiportal_recording', 'filename', $stored->get_filename(), ['id' => $id]);
            }
        }

        return self::format_recording($DB->get_record('local_btiportal_recording', ['id' => $id], '*', MUST_EXIST));
    }

    public static function list_recordings_for_courses(array $courseids, int $limit = 100): array {
        global $DB;
        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        if (!$courseids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'local_btiportal_recording',
            "courseid {$insql}",
            $params,
            'timecreated DESC',
            '*',
            0,
            $limit
        );
        return array_map([self::class, 'format_recording'], array_values($records));
    }

    private static function format_announcement(\stdClass $r): array {
        global $DB;
        $author = $DB->get_record('user', ['id' => $r->authorid], 'id,firstname,lastname');
        $course = $DB->get_record('course', ['id' => $r->courseid], 'id,shortname,fullname');
        $departmentid = (int)($r->departmentid ?? 0);
        $deptname = '';
        $deptcode = '';
        if ($departmentid > 0) {
            $dept = $DB->get_record('local_btiportal_department', ['id' => $departmentid], 'id,code,name');
            if ($dept) {
                $deptcode = (string)$dept->code;
                $deptname = (string)$dept->name;
            }
        }
        $scope = (string)($r->scope ?? (($departmentid > 0) ? 'department' : (((int)$r->courseid > 1) ? 'course' : 'university')));
        return [
            'id' => (int)$r->id,
            'courseid' => (int)$r->courseid,
            'coursecode' => $course->shortname ?? '',
            'coursename' => $course->fullname ?? '',
            'authorid' => (int)$r->authorid,
            'authorname' => $author ? trim($author->firstname . ' ' . $author->lastname) : '',
            'title' => $r->title,
            'body' => $r->body,
            'eventid' => (int)($r->eventid ?? 0),
            'timecreated' => (int)$r->timecreated,
            'departmentid' => $departmentid,
            'departmentcode' => $deptcode,
            'departmentname' => $deptname,
            'scope' => $scope,
            'status' => (string)($r->status ?? 'published'),
        ];
    }

    private static function format_recording(\stdClass $r): array {
        global $DB;
        $author = $DB->get_record('user', ['id' => $r->authorid], 'id,firstname,lastname');
        $course = $DB->get_record('course', ['id' => $r->courseid], 'id,shortname,fullname');
        return [
            'id' => (int)$r->id,
            'courseid' => (int)$r->courseid,
            'sectionid' => (int)($r->sectionid ?? 0),
            'unitname' => (string)($r->unitname ?? ''),
            'coursecode' => $course->shortname ?? '',
            'coursename' => $course->fullname ?? '',
            'authorid' => (int)$r->authorid,
            'authorname' => $author ? trim($author->firstname . ' ' . $author->lastname) : '',
            'title' => $r->title,
            'type' => $r->type,
            'sessiondate' => $r->sessiondate,
            'description' => $r->description,
            'link' => $r->link ?? '',
            'filename' => $r->filename ?? '',
            'timecreated' => (int)$r->timecreated,
        ];
    }

    /**
     * Create a site-wide calendar event (admin broadcasts).
     */
    public static function create_site_calendar_event(
        string $name,
        string $description,
        int $timestart,
        int $timeduration = 0
    ): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $event = new \stdClass();
        $event->name = $name;
        $event->description = [
            'text' => $description,
            'format' => FORMAT_HTML,
        ];
        $event->courseid = SITEID;
        $event->eventtype = 'site';
        $event->timestart = $timestart;
        $event->timeduration = max(0, $timeduration);
        $event->visible = 1;
        $event->modulename = '';
        $event->instance = 0;

        $created = \calendar_event::create($event, false);
        return (object) [
            'id' => (int)$created->id,
            'name' => $created->name,
            'courseid' => (int)$created->courseid,
            'timestart' => (int)$created->timestart,
            'timeduration' => (int)$created->timeduration,
            'eventtype' => $created->eventtype,
        ];
    }

    public static function publish_event(
        int $courseid,
        int $authorid,
        string $title,
        string $body,
        int $timestart,
        int $timeduration = 0,
        int $draftitemid = 0,
        string $scope = 'course',
        int $departmentid = 0
    ): array {
        global $DB, $CFG;

        $scope = self::normalize_content_scope($scope, $courseid, $departmentid);
        $record = (object) [
            'courseid' => $courseid,
            'authorid' => $authorid,
            'title' => $title,
            'body' => $body,
            'timestart' => $timestart,
            'timeduration' => max(0, $timeduration),
            'eventid' => 0,
            'imageurl' => '',
            'filename' => '',
            'timecreated' => time(),
        ];
        $columns = $DB->get_columns('local_btiportal_event');
        if (isset($columns['departmentid'])) {
            $record->departmentid = $departmentid;
        }
        if (isset($columns['scope'])) {
            $record->scope = $scope;
        }
        if (isset($columns['status'])) {
            $record->status = 'published';
        }

        $id = $DB->insert_record('local_btiportal_event', $record);

        $imagehtml = '';
        if ($draftitemid > 0) {
            require_once($CFG->libdir . '/filelib.php');
            $context = \context_system::instance();
            file_save_draft_area_files(
                $draftitemid,
                $context->id,
                'local_btiportal',
                'eventimage',
                $id,
                ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']]
            );

            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'local_btiportal', 'eventimage', $id, 'filename', false);
            $stored = reset($files);
            if ($stored) {
                $url = \moodle_url::make_webservice_pluginfile_url(
                    $context->id,
                    'local_btiportal',
                    'eventimage',
                    $id,
                    '/',
                    $stored->get_filename()
                );
                $imageurl = $url->out(false);
                $DB->set_field('local_btiportal_event', 'imageurl', $imageurl, ['id' => $id]);
                $DB->set_field('local_btiportal_event', 'filename', $stored->get_filename(), ['id' => $id]);
                $safe = s($imageurl);
                $imagehtml = '<p><img src="' . $safe . '" alt="" style="max-width:100%;height:auto;display:block;object-fit:cover;" /></p>';
            }
        }

        $description = $imagehtml . format_text($body, FORMAT_MOODLE);
        if ($courseid <= 1) {
            $event = self::create_site_calendar_event($title, $description, $timestart, $timeduration);
        } else {
            $event = self::create_course_calendar_event($courseid, $title, $description, $timestart, $timeduration);
        }
        $DB->set_field('local_btiportal_event', 'eventid', $event->id, ['id' => $id]);

        if (class_exists(audit_helper::class)) {
            audit_helper::log([
                'action' => 'event_publish',
                'entitytype' => 'event',
                'entityid' => $id,
                'departmentid' => $departmentid,
                'after' => ['scope' => $scope, 'courseid' => $courseid, 'title' => $title],
                'result' => 'success',
                'sourcemodule' => 'content',
            ], $authorid);
        }

        return self::format_event($DB->get_record('local_btiportal_event', ['id' => $id], '*', MUST_EXIST));
    }

    /**
     * Normalize content publication scope to supported values only.
     */
    public static function normalize_content_scope(string $scope, int $courseid, int $departmentid): string {
        $scope = strtolower(trim($scope));
        $allowed = ['university', 'department', 'course'];
        if (!in_array($scope, $allowed, true)) {
            if ($departmentid > 0) {
                return 'department';
            }
            if ($courseid > 1) {
                return 'course';
            }
            return 'university';
        }
        return $scope;
    }

    public static function list_events_for_courses(array $courseids, int $limit = 100): array {
        global $DB;
        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        // Always include site-wide events (courseid 0 or SITEID).
        $courseids[] = (int)SITEID;
        $courseids[] = 0;
        $courseids = array_values(array_unique($courseids));
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'local_btiportal_event',
            "courseid {$insql}",
            $params,
            'timestart DESC',
            '*',
            0,
            $limit
        );
        return array_map([self::class, 'format_event'], array_values($records));
    }

    public static function list_all_events(int $limit = 100): array {
        global $DB;
        $records = $DB->get_records('local_btiportal_event', null, 'timestart DESC', '*', 0, $limit);
        return array_map([self::class, 'format_event'], array_values($records));
    }

    public static function publish_resource(
        int $courseid,
        int $sectionid,
        int $authorid,
        string $title,
        string $description = '',
        string $link = '',
        string $filename = '',
        int $draftitemid = 0
    ): array {
        global $DB, $CFG;

        if ($draftitemid <= 0 && trim($link) === '') {
            throw new \invalid_parameter_exception('Upload a document or provide a link');
        }

        $id = $DB->insert_record('local_btiportal_resource', (object) [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'authorid' => $authorid,
            'title' => $title,
            'description' => $description,
            'link' => $link,
            'filename' => $filename,
            'timecreated' => time(),
        ]);

        if ($draftitemid > 0) {
            require_once($CFG->libdir . '/filelib.php');
            $context = \context_system::instance();
            file_save_draft_area_files(
                $draftitemid,
                $context->id,
                'local_btiportal',
                'resource',
                $id,
                [
                    'subdirs' => 0,
                    'maxfiles' => 1,
                    'accepted_types' => ['.pdf', '.doc', '.docx', '.ppt', '.pptx', '.xls', '.xlsx', '.txt', '.zip', '.png', '.jpg', '.jpeg'],
                ]
            );

            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'local_btiportal', 'resource', $id, 'filename', false);
            $stored = reset($files);
            if ($stored) {
                $url = \moodle_url::make_webservice_pluginfile_url(
                    $context->id,
                    'local_btiportal',
                    'resource',
                    $id,
                    '/',
                    $stored->get_filename()
                );
                $DB->set_field('local_btiportal_resource', 'link', $url->out(false), ['id' => $id]);
                $DB->set_field('local_btiportal_resource', 'filename', $stored->get_filename(), ['id' => $id]);
            }
        }

        return self::format_resource(
            $DB->get_record('local_btiportal_resource', ['id' => $id], '*', MUST_EXIST),
            $authorid
        );
    }

    public static function list_resources_for_courses(array $courseids, int $userid, int $limit = 200): array {
        global $DB;
        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        if (!$courseids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'local_btiportal_resource',
            "courseid {$insql}",
            $params,
            'timecreated DESC',
            '*',
            0,
            $limit
        );
        return array_map(
            fn($r) => self::format_resource($r, $userid),
            array_values($records)
        );
    }

    public static function set_resource_completion(int $resourceid, int $userid, bool $completed): array {
        global $DB;

        $resource = $DB->get_record('local_btiportal_resource', ['id' => $resourceid], '*', MUST_EXIST);
        if (!self::user_can_view_course($userid, (int)$resource->courseid) && !is_siteadmin($userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'mark resource complete');
        }

        $existing = $DB->get_record('local_btiportal_resource_completion', [
            'resourceid' => $resourceid,
            'userid' => $userid,
        ]);

        if ($completed) {
            if (!$existing) {
                $DB->insert_record('local_btiportal_resource_completion', (object) [
                    'resourceid' => $resourceid,
                    'userid' => $userid,
                    'timecreated' => time(),
                ]);
            }
            // Auto-issue a persisted certificate once portal resource progress hits 100%.
            self::ensure_certificate_for_course($userid, (int)$resource->courseid);
        } else if ($existing) {
            $DB->delete_records('local_btiportal_resource_completion', ['id' => $existing->id]);
        }

        return self::format_resource($resource, $userid);
    }

    /**
     * Whether every portal resource for the course is marked complete by the user.
     * Matches portal courseProgressWithPortalResources when Moodle activity completion is unused:
     * portal resources are the countable items (Moodle modules with completion===1 also count in the UI,
     * but on this instance none are configured — certificate gating uses portal resources only).
     */
    public static function portal_resources_fully_complete(int $userid, int $courseid): bool {
        global $DB;
        $resources = $DB->get_records('local_btiportal_resource', ['courseid' => $courseid], '', 'id');
        if (!$resources) {
            return false;
        }
        foreach ($resources as $resource) {
            if (!$DB->record_exists('local_btiportal_resource_completion', [
                'resourceid' => (int)$resource->id,
                'userid' => $userid,
            ])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Issue (or return existing) Moodle-stored PDF certificate when portal resources are 100% complete.
     * Automatic: fires from set_resource_completion and from ensure_certificate WS (catch-up).
     */
    /**
     * @param string $portalbaseurl Portal origin used in certificate QR (e.g. https://portal.example.com)
     * @param bool $force Skip 100% completion check (admin reissue / CLI).
     */
    public static function ensure_certificate_for_course(int $userid, int $courseid, string $portalbaseurl = '', bool $force = false): ?array {
        global $DB, $CFG;

        if ($courseid <= 1) {
            return null;
        }
        if (!self::user_can_view_course($userid, $courseid) && !is_siteadmin($userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'issue certificate');
        }

        $existing = $DB->get_record('local_btiportal_certificate', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);
        if ($existing) {
            return self::format_certificate($existing);
        }

        if (!$force && !self::portal_resources_fully_complete($userid, $courseid)) {
            return null;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], 'id,shortname,fullname', MUST_EXIST);
        $studentname = trim($user->firstname . ' ' . $user->lastname);
        if ($studentname === '') {
            $studentname = 'Student';
        }

        // Completion date = when 100% was first reached (latest resource completion timestamp).
        $completedat = (int)$DB->get_field_sql(
            "SELECT MAX(c.timecreated)
               FROM {local_btiportal_resource_completion} c
               JOIN {local_btiportal_resource} r ON r.id = c.resourceid
              WHERE r.courseid = ? AND c.userid = ?",
            [$courseid, $userid]
        );
        if ($completedat <= 0) {
            $completedat = time();
        }

        $id = $DB->insert_record('local_btiportal_certificate', (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'studentname' => $studentname,
            'coursename' => $course->fullname,
            'coursecode' => $course->shortname,
            'completedat' => $completedat,
            'fileurl' => '',
            'filename' => '',
            'timecreated' => time(),
        ]);

        require_once($CFG->libdir . '/pdflib.php');
        require_once($CFG->libdir . '/filelib.php');

        $certid = 'BTI-' . preg_replace('/\s+/', '', $course->shortname) . '-' . $id;
        $verifyurl = self::certificate_verify_url(
            $portalbaseurl,
            $studentname,
            $course->fullname,
            $course->shortname,
            $completedat,
            $certid
        );

        $pdfbytes = certificate_pdf_helper::render([
            'studentname' => $studentname,
            'coursename' => $course->fullname,
            'coursecode' => $course->shortname,
            'certid' => $certid,
            'completedat' => $completedat,
            'verifyurl' => $verifyurl,
        ]);
        $filename = 'certificate-' . $course->shortname . '-' . $userid . '.pdf';
        $filename = clean_filename($filename);

        $context = \context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_btiportal', 'certificate', $id);
        $stored = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_btiportal',
            'filearea' => 'certificate',
            'itemid' => $id,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
        ], $pdfbytes);

        $url = \moodle_url::make_webservice_pluginfile_url(
            $context->id,
            'local_btiportal',
            'certificate',
            $id,
            '/',
            $stored->get_filename()
        );
        $DB->set_field('local_btiportal_certificate', 'fileurl', $url->out(false), ['id' => $id]);
        $DB->set_field('local_btiportal_certificate', 'filename', $stored->get_filename(), ['id' => $id]);

        return self::format_certificate(
            $DB->get_record('local_btiportal_certificate', ['id' => $id], '*', MUST_EXIST)
        );
    }

    public static function list_certificates_for_user(int $userid, array $courseids = []): array {
        global $DB;
        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        if ($courseids) {
            list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $records = $DB->get_records_select(
                'local_btiportal_certificate',
                "userid = :userid AND courseid {$insql}",
                $params,
                'completedat DESC'
            );
        } else {
            $records = $DB->get_records('local_btiportal_certificate', ['userid' => $userid], 'completedat DESC');
        }
        return array_map([self::class, 'format_certificate'], array_values($records));
    }

    /**
     * Public portal URL encoded into certificate QR codes.
     */
    public static function certificate_verify_url(
        string $portalbaseurl,
        string $studentname,
        string $coursename,
        string $coursecode,
        int $completedat,
        string $certid
    ): string {
        $base = trim($portalbaseurl);
        if ($base === '') {
            $base = get_config('local_btiportal', 'portalbaseurl') ?: 'http://localhost:8080';
        }
        $base = rtrim($base, '/');
        $payload = [
            't' => 'cert',
            'n' => $studentname,
            'c' => $coursename,
            'code' => $coursecode,
            'd' => $completedat,
            'id' => $certid,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $base . '/verify/certificate?p=' . $b64;
    }

    private static function format_certificate(\stdClass $r): array {
        return [
            'id' => (int)$r->id,
            'userid' => (int)$r->userid,
            'courseid' => (int)$r->courseid,
            'studentname' => (string)$r->studentname,
            'coursename' => (string)$r->coursename,
            'coursecode' => (string)$r->coursecode,
            'completedat' => (int)$r->completedat,
            'fileurl' => (string)($r->fileurl ?? ''),
            'filename' => (string)($r->filename ?? ''),
            'timecreated' => (int)$r->timecreated,
        ];
    }

    private static function format_event(\stdClass $r): array {
        global $DB;
        $author = $DB->get_record('user', ['id' => $r->authorid], 'id,firstname,lastname');
        $course = $DB->get_record('course', ['id' => $r->courseid], 'id,shortname,fullname');
        $sitewide = (int)$r->courseid <= 1;
        $departmentid = (int)($r->departmentid ?? 0);
        $deptname = '';
        $deptcode = '';
        if ($departmentid > 0) {
            $dept = $DB->get_record('local_btiportal_department', ['id' => $departmentid], 'id,code,name');
            if ($dept) {
                $deptcode = (string)$dept->code;
                $deptname = (string)$dept->name;
            }
        }
        $scope = (string)($r->scope ?? (($departmentid > 0) ? 'department' : ($sitewide ? 'university' : 'course')));
        return [
            'id' => (int)$r->id,
            'courseid' => (int)$r->courseid,
            'coursecode' => $sitewide ? 'ALL' : ($course->shortname ?? ''),
            'coursename' => $sitewide ? 'All users' : ($course->fullname ?? ''),
            'authorid' => (int)$r->authorid,
            'authorname' => $author ? trim($author->firstname . ' ' . $author->lastname) : '',
            'title' => $r->title,
            'body' => $r->body,
            'timestart' => (int)$r->timestart,
            'timeduration' => (int)$r->timeduration,
            'eventid' => (int)($r->eventid ?? 0),
            'imageurl' => (string)($r->imageurl ?? ''),
            'filename' => (string)($r->filename ?? ''),
            'timecreated' => (int)$r->timecreated,
            'departmentid' => $departmentid,
            'departmentcode' => $deptcode,
            'departmentname' => $deptname,
            'scope' => $scope,
            'status' => (string)($r->status ?? 'published'),
        ];
    }

    private static function format_resource(\stdClass $r, int $userid): array {
        global $DB;
        $author = $DB->get_record('user', ['id' => $r->authorid], 'id,firstname,lastname');
        $course = $DB->get_record('course', ['id' => $r->courseid], 'id,shortname,fullname');
        $completed = $DB->record_exists('local_btiportal_resource_completion', [
            'resourceid' => $r->id,
            'userid' => $userid,
        ]);
        return [
            'id' => (int)$r->id,
            'courseid' => (int)$r->courseid,
            'sectionid' => (int)$r->sectionid,
            'coursecode' => $course->shortname ?? '',
            'coursename' => $course->fullname ?? '',
            'authorid' => (int)$r->authorid,
            'authorname' => $author ? trim($author->firstname . ' ' . $author->lastname) : '',
            'title' => $r->title,
            'description' => $r->description ?? '',
            'link' => $r->link ?? '',
            'filename' => $r->filename ?? '',
            'completed' => $completed,
            'timecreated' => (int)$r->timecreated,
        ];
    }
}
