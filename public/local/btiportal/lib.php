<?php
/**
 * Plugin file callback — serve uploaded recordings, event images, resources, and certificates.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_btiportal_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    $allowed = [
        'recording', 'eventimage', 'resource', 'certificate',
        'courseimage', 'unitimage', 'programmeimage', 'courseunitimage',
    ];
    if (!in_array($filearea, $allowed, true)) {
        return false;
    }

    require_login();

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    if ($filearea === 'programmeimage' || $filearea === 'courseunitimage') {
        // Catalogue covers: any logged-in portal user may view (token/pluginfile).
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_btiportal', $filearea, $itemid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, 0, 0, $forcedownload, $options);
        return true;
    }

    if ($filearea === 'courseimage' || $filearea === 'unitimage') {
        // Cover images: courseimage itemid=courseid; unitimage itemid=sectionid.
        if ($filearea === 'courseimage') {
            $coursecontext = context_course::instance($itemid, IGNORE_MISSING);
            if (!$coursecontext) {
                return false;
            }
            if (!is_enrolled($coursecontext, null, '', true)
                && !has_capability('moodle/course:view', $coursecontext)
                && !is_siteadmin()) {
                return false;
            }
        } else {
            // Unit images are visible to any logged-in user with course access via caller context.
            // Auth already required above.
        }
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_btiportal', $filearea, $itemid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, 0, 0, $forcedownload, $options);
        return true;
    }

    if ($filearea === 'certificate') {
        $record = $DB->get_record('local_btiportal_certificate', ['id' => $itemid], '*', IGNORE_MISSING);
        if (!$record) {
            return false;
        }
        if ((int)$record->userid !== (int)$USER->id && !is_siteadmin()) {
            return false;
        }
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_btiportal', $filearea, $itemid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, 0, 0, $forcedownload, $options);
        return true;
    }

    if ($filearea === 'recording') {
        $record = $DB->get_record('local_btiportal_recording', ['id' => $itemid], '*', IGNORE_MISSING);
        if (!$record) {
            return false;
        }
        $courseid = (int)$record->courseid;
    } else if ($filearea === 'eventimage') {
        $record = $DB->get_record('local_btiportal_event', ['id' => $itemid], '*', IGNORE_MISSING);
        if (!$record) {
            return false;
        }
        $courseid = (int)$record->courseid;
        // Site-wide event images are visible to any logged-in user.
        if ($courseid <= 1) {
            $fs = get_file_storage();
            $file = $fs->get_file($context->id, 'local_btiportal', $filearea, $itemid, $filepath, $filename);
            if (!$file || $file->is_directory()) {
                return false;
            }
            send_stored_file($file, 0, 0, $forcedownload, $options);
            return true;
        }
    } else {
        $record = $DB->get_record('local_btiportal_resource', ['id' => $itemid], '*', IGNORE_MISSING);
        if (!$record) {
            return false;
        }
        $courseid = (int)$record->courseid;
    }

    $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
    if (!$coursecontext) {
        return false;
    }
    if (!is_enrolled($coursecontext, null, '', true)
        && !has_capability('moodle/course:update', $coursecontext)
        && !is_siteadmin()) {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_btiportal', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
