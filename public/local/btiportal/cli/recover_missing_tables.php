<?php
/**
 * Recover missing local_btiportal tables on a production database.
 *
 * Creates ONLY tables that are absent — does NOT insert data, register WS functions,
 * alter config_plugins, or run any data migrations.
 *
 * Dry-run (default):
 *   php local/btiportal/cli/recover_missing_tables.php
 *
 * Execute:
 *   php local/btiportal/cli/recover_missing_tables.php --execute
 *
 * Safe to re-run: every CREATE is guarded by table_exists().
 */
define('CLI_SCRIPT', true);

require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['help' => false, 'execute' => false],
    ['h' => 'help', 'x' => 'execute']
);

if ($options['help']) {
    cli_writeln('Recover missing local_btiportal tables.');
    cli_writeln('  --execute   Actually create tables (default is dry-run)');
    exit(0);
}

$dryrun = empty($options['execute']);
if ($dryrun) {
    cli_writeln('=== DRY-RUN MODE — no tables will be created. Pass --execute to apply. ===');
    cli_writeln('');
}

global $DB;
$prefix = $CFG->prefix;

$tableexists = function (string $name) use ($DB, $prefix): bool {
    return (bool) $DB->record_exists_sql(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$prefix . $name]
    );
};

$created = 0;
$skipped = 0;

$tables = [];

// ---------- Table definitions (exact replicas from db/upgrade.php) ----------

// 1. local_btiportal_unit (2026071701)
$tables['local_btiportal_unit'] = "
    CREATE TABLE {$prefix}local_btiportal_unit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        courseid INT NOT NULL,
        sectionid INT NOT NULL,
        teacheruserid INT NOT NULL,
        zoomcmid BIGINT NOT NULL DEFAULT 0,
        timecreated INT NOT NULL,
        UNIQUE KEY unit_course_section (courseid, sectionid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 2. local_btiportal_announcement (2026072019)
$tables['local_btiportal_announcement'] = "
    CREATE TABLE {$prefix}local_btiportal_announcement (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseid BIGINT NOT NULL,
        departmentid BIGINT NOT NULL DEFAULT 0,
        scope VARCHAR(32) NOT NULL DEFAULT 'course',
        status VARCHAR(32) NOT NULL DEFAULT 'published',
        timepublish BIGINT NOT NULL DEFAULT 0,
        timeexpire BIGINT NOT NULL DEFAULT 0,
        authorid BIGINT NOT NULL,
        title VARCHAR(255) NOT NULL,
        body LONGTEXT NOT NULL,
        eventid BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        KEY courseid_idx (courseid),
        KEY authorid_idx (authorid),
        KEY departmentid_idx (departmentid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 3. local_btiportal_recording (2026072019)
$tables['local_btiportal_recording'] = "
    CREATE TABLE {$prefix}local_btiportal_recording (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseid BIGINT NOT NULL,
        authorid BIGINT NOT NULL,
        title VARCHAR(255) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'class',
        sessiondate VARCHAR(32) NOT NULL,
        description LONGTEXT NOT NULL,
        link LONGTEXT,
        filename VARCHAR(255),
        timecreated BIGINT NOT NULL,
        KEY courseid_idx (courseid),
        KEY authorid_idx (authorid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 4. local_btiportal_event (2026072020 + 2026080306 columns)
$tables['local_btiportal_event'] = "
    CREATE TABLE {$prefix}local_btiportal_event (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseid BIGINT NOT NULL,
        departmentid BIGINT NOT NULL DEFAULT 0,
        scope VARCHAR(32) NOT NULL DEFAULT 'course',
        status VARCHAR(32) NOT NULL DEFAULT 'published',
        authorid BIGINT NOT NULL,
        title VARCHAR(255) NOT NULL,
        body LONGTEXT NOT NULL,
        timestart BIGINT NOT NULL,
        timeduration BIGINT NOT NULL DEFAULT 0,
        eventid BIGINT NOT NULL DEFAULT 0,
        imageurl LONGTEXT,
        filename VARCHAR(255),
        timecreated BIGINT NOT NULL,
        KEY courseid_idx (courseid),
        KEY timestart_idx (timestart),
        KEY departmentid_idx (departmentid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 5. local_btiportal_resource (2026072020)
$tables['local_btiportal_resource'] = "
    CREATE TABLE {$prefix}local_btiportal_resource (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseid BIGINT NOT NULL,
        sectionid BIGINT NOT NULL DEFAULT 0,
        authorid BIGINT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description LONGTEXT,
        link LONGTEXT,
        filename VARCHAR(255),
        timecreated BIGINT NOT NULL,
        KEY courseid_idx (courseid),
        KEY sectionid_idx (sectionid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 6. local_btiportal_resource_completion (2026072020)
$tables['local_btiportal_resource_completion'] = "
    CREATE TABLE {$prefix}local_btiportal_resource_completion (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        resourceid BIGINT NOT NULL,
        userid BIGINT NOT NULL,
        timecreated BIGINT NOT NULL,
        UNIQUE KEY resource_user_uq (resourceid, userid),
        KEY userid_idx (userid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 7. local_btiportal_certificate (2026072021)
$tables['local_btiportal_certificate'] = "
    CREATE TABLE {$prefix}local_btiportal_certificate (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        courseid BIGINT NOT NULL,
        studentname VARCHAR(255) NOT NULL,
        coursename VARCHAR(255) NOT NULL,
        coursecode VARCHAR(100) NOT NULL DEFAULT '',
        completedat BIGINT NOT NULL,
        fileurl LONGTEXT,
        filename VARCHAR(255),
        timecreated BIGINT NOT NULL,
        UNIQUE KEY user_course_uq (userid, courseid),
        KEY courseid_idx (courseid),
        KEY userid_idx (userid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 8. local_btiportal_unit_gradeitem (2026072300)
$tables['local_btiportal_unit_gradeitem'] = "
    CREATE TABLE {$prefix}local_btiportal_unit_gradeitem (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseid BIGINT NOT NULL,
        unitid BIGINT NOT NULL,
        gradeitemid BIGINT NOT NULL,
        itemmodule VARCHAR(100) NOT NULL DEFAULT '',
        itemtype VARCHAR(30) NOT NULL DEFAULT '',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY course_unit_item_uq (courseid, unitid, gradeitemid),
        KEY courseid_idx (courseid),
        KEY unitid_idx (unitid),
        KEY gradeitemid_idx (gradeitemid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 9. local_btiportal_assignment_meta (2026072303)
$tables['local_btiportal_assignment_meta'] = "
    CREATE TABLE {$prefix}local_btiportal_assignment_meta (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        assignmentid BIGINT NOT NULL,
        courseid BIGINT NOT NULL,
        unitid BIGINT NOT NULL,
        category VARCHAR(20) NOT NULL,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY assignmentid_uq (assignmentid),
        KEY courseid_idx (courseid),
        KEY unitid_idx (unitid),
        KEY category_idx (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 10. local_btiportal_inactive_alert (2026072800)
$tables['local_btiportal_inactive_alert'] = "
    CREATE TABLE {$prefix}local_btiportal_inactive_alert (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseid BIGINT NOT NULL,
        userid BIGINT NOT NULL,
        lastaccess BIGINT NOT NULL,
        timenotified BIGINT NOT NULL,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY course_user_uq (courseid, userid),
        KEY userid_idx (userid),
        KEY lastaccess_idx (lastaccess)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 11. local_btiportal_programme (2026080201 + 2026080301 columns + 2026080605 imageurl)
$tables['local_btiportal_programme'] = "
    CREATE TABLE {$prefix}local_btiportal_programme (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description LONGTEXT,
        departmentid BIGINT NOT NULL DEFAULT 0,
        moodlecategoryid BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        awardtype VARCHAR(32) NOT NULL DEFAULT 'bachelor',
        duration DECIMAL(5,1) NOT NULL DEFAULT 0,
        durationunit VARCHAR(16) NOT NULL DEFAULT 'years',
        imageurl VARCHAR(500) NOT NULL DEFAULT '',
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY programme_code_uq (code),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 12. local_btiportal_curriculum (2026080201 + 2026080301 usermodified)
$tables['local_btiportal_curriculum'] = "
    CREATE TABLE {$prefix}local_btiportal_curriculum (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        programmeid BIGINT NOT NULL,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        version VARCHAR(50) NOT NULL,
        description LONGTEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        effectivefrom BIGINT NOT NULL DEFAULT 0,
        effectiveto BIGINT NOT NULL DEFAULT 0,
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY curriculum_prog_version_uq (programmeid, version),
        KEY programmeid_idx (programmeid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 13. local_btiportal_academic_year (2026080202)
$tables['local_btiportal_academic_year'] = "
    CREATE TABLE {$prefix}local_btiportal_academic_year (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        code VARCHAR(50) NOT NULL,
        startdate BIGINT NOT NULL,
        enddate BIGINT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'upcoming',
        iscurrent TINYINT(1) NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY academic_year_name_uq (name),
        KEY code_idx (code),
        KEY iscurrent_idx (iscurrent),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 14. local_btiportal_semester (2026080202)
$tables['local_btiportal_semester'] = "
    CREATE TABLE {$prefix}local_btiportal_semester (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        academicyearid BIGINT NOT NULL,
        name VARCHAR(100) NOT NULL,
        code VARCHAR(50) NOT NULL,
        sequence INT NOT NULL,
        startdate BIGINT NOT NULL,
        enddate BIGINT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'upcoming',
        iscurrent TINYINT(1) NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY semester_year_sequence_uq (academicyearid, sequence),
        KEY academicyearid_idx (academicyearid),
        KEY iscurrent_idx (iscurrent),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 15. local_btiportal_course_unit (2026080203 + 2026080605 imageurl + 2026081302 moodleverifiedat)
// NOTE: The original unique key course_unit_map_uq was dropped in 2026081300.
$tables['local_btiportal_course_unit'] = "
    CREATE TABLE {$prefix}local_btiportal_course_unit (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description LONGTEXT,
        programmeid BIGINT NOT NULL,
        curriculumid BIGINT NOT NULL,
        yearofstudy INT NOT NULL,
        semestersequence INT NOT NULL,
        courseid BIGINT NOT NULL,
        sectionid BIGINT NOT NULL,
        credithours DECIMAL(6,2) NOT NULL,
        unittype VARCHAR(20) NOT NULL DEFAULT 'core',
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        isrequired TINYINT(1) NOT NULL DEFAULT 1,
        imageurl VARCHAR(500) NOT NULL DEFAULT '',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        moodleverifiedat BIGINT NOT NULL DEFAULT 0,
        createdby BIGINT NOT NULL DEFAULT 0,
        modifiedby BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY course_unit_code_uq (curriculumid, code),
        KEY programmeid_idx (programmeid),
        KEY courseid_idx (courseid),
        KEY sectionid_idx (sectionid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 16. local_btiportal_course_unit_prerequisite (2026080204)
$tables['local_btiportal_course_unit_prerequisite'] = "
    CREATE TABLE {$prefix}local_btiportal_course_unit_prerequisite (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseunitid BIGINT NOT NULL,
        prerequisitecourseunitid BIGINT NOT NULL,
        timecreated BIGINT NOT NULL,
        createdby BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY course_unit_prereq_uq (courseunitid, prerequisitecourseunitid),
        KEY courseunitid_idx (courseunitid),
        KEY prerequisitecourseunitid_idx (prerequisitecourseunitid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 17. local_btiportal_course_unit_teacher (2026080204 + 2026080304 period columns)
$tables['local_btiportal_course_unit_teacher'] = "
    CREATE TABLE {$prefix}local_btiportal_course_unit_teacher (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        courseunitid BIGINT NOT NULL,
        userid BIGINT NOT NULL,
        teachingrole VARCHAR(40) NOT NULL,
        isprimary TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        academicyearid BIGINT NOT NULL DEFAULT 0,
        semesterid BIGINT NOT NULL DEFAULT 0,
        assignedby BIGINT NOT NULL DEFAULT 0,
        timeassigned BIGINT NOT NULL,
        timeremoved BIGINT NOT NULL DEFAULT 0,
        KEY courseunitid_idx (courseunitid),
        KEY userid_idx (userid),
        KEY status_idx (status),
        KEY teachingrole_idx (teachingrole),
        KEY academicyearid_idx (academicyearid),
        KEY semesterid_idx (semesterid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 18. local_btiportal_school (2026080301)
$tables['local_btiportal_school'] = "
    CREATE TABLE {$prefix}local_btiportal_school (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description LONGTEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY school_code_uq (code),
        KEY code_idx (code),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 19. local_btiportal_department (2026080301)
$tables['local_btiportal_department'] = "
    CREATE TABLE {$prefix}local_btiportal_department (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        schoolid BIGINT NOT NULL,
        code VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description LONGTEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY department_code_uq (code),
        KEY schoolid_idx (schoolid),
        KEY code_idx (code),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 20. local_btiportal_curriculum_year (2026080301)
$tables['local_btiportal_curriculum_year'] = "
    CREATE TABLE {$prefix}local_btiportal_curriculum_year (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        curriculumid BIGINT NOT NULL,
        yearnumber INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description LONGTEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY curriculum_year_uq (curriculumid, yearnumber),
        KEY curriculumid_idx (curriculumid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 21. local_btiportal_curriculum_semester (2026080301)
$tables['local_btiportal_curriculum_semester'] = "
    CREATE TABLE {$prefix}local_btiportal_curriculum_semester (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        curriculumyearid BIGINT NOT NULL,
        semesternumber INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        creditrequirement DECIMAL(6,2) NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY curriculum_semester_uq (curriculumyearid, semesternumber),
        KEY curriculumyearid_idx (curriculumyearid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 22. local_btiportal_lecturer_dept (2026080301 + 2026080306 leadership columns)
$tables['local_btiportal_lecturer_dept'] = "
    CREATE TABLE {$prefix}local_btiportal_lecturer_dept (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        departmentid BIGINT NOT NULL,
        membershiprole VARCHAR(64) NOT NULL DEFAULT 'member',
        isprimary TINYINT(1) NOT NULL DEFAULT 0,
        isdepartmentleader TINYINT(1) NOT NULL DEFAULT 0,
        timestart BIGINT NOT NULL DEFAULT 0,
        timeend BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY lecturer_dept_uq (userid, departmentid),
        KEY userid_idx (userid),
        KEY departmentid_idx (departmentid),
        KEY status_idx (status),
        KEY isdepartmentleader_idx (isdepartmentleader)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 23. local_btiportal_student_profile (2026080301 + 2026080602 registry columns)
$tables['local_btiportal_student_profile'] = "
    CREATE TABLE {$prefix}local_btiportal_student_profile (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        schoolid BIGINT NOT NULL DEFAULT 0,
        departmentid BIGINT NOT NULL DEFAULT 0,
        programmeid BIGINT NOT NULL,
        curriculumid BIGINT NOT NULL,
        curriculumyearid BIGINT NOT NULL DEFAULT 0,
        curriculumsemesterid BIGINT NOT NULL DEFAULT 0,
        academicyearid BIGINT NOT NULL DEFAULT 0,
        semesterid BIGINT NOT NULL DEFAULT 0,
        admissiondate BIGINT NOT NULL DEFAULT 0,
        admissionstatus VARCHAR(32) NOT NULL DEFAULT 'active',
        academicstatus VARCHAR(32) NOT NULL DEFAULT 'active',
        expectedcompletion BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        entrytype VARCHAR(32) DEFAULT '',
        studymode VARCHAR(32) DEFAULT '',
        deliverymode VARCHAR(32) DEFAULT '',
        campus VARCHAR(128) DEFAULT '',
        intake VARCHAR(128) DEFAULT '',
        scholarship VARCHAR(255) DEFAULT '',
        sponsor VARCHAR(255) DEFAULT '',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY student_profile_user_uq (userid),
        KEY programmeid_idx (programmeid),
        KEY curriculumid_idx (curriculumid),
        KEY academicyearid_idx (academicyearid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 24. local_btiportal_staff_org (2026080301)
$tables['local_btiportal_staff_org'] = "
    CREATE TABLE {$prefix}local_btiportal_staff_org (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        schoolid BIGINT NOT NULL DEFAULT 0,
        departmentid BIGINT NOT NULL DEFAULT 0,
        isprimary TINYINT(1) NOT NULL DEFAULT 0,
        jobtitle VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY staff_org_uq (userid, departmentid),
        KEY userid_idx (userid),
        KEY departmentid_idx (departmentid),
        KEY schoolid_idx (schoolid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 25. local_btiportal_student_acad_hist (2026080303)
$tables['local_btiportal_student_acad_hist'] = "
    CREATE TABLE {$prefix}local_btiportal_student_acad_hist (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        actiontype VARCHAR(40) NOT NULL,
        prevschoolid BIGINT NOT NULL DEFAULT 0,
        prevdepartmentid BIGINT NOT NULL DEFAULT 0,
        prevprogrammeid BIGINT NOT NULL DEFAULT 0,
        prevcurriculumid BIGINT NOT NULL DEFAULT 0,
        prevcurriculumyearid BIGINT NOT NULL DEFAULT 0,
        prevcurriculumsemesterid BIGINT NOT NULL DEFAULT 0,
        prevacademicyearid BIGINT NOT NULL DEFAULT 0,
        prevsemesterid BIGINT NOT NULL DEFAULT 0,
        prevacademicstatus VARCHAR(32) NOT NULL DEFAULT '',
        newschoolid BIGINT NOT NULL DEFAULT 0,
        newdepartmentid BIGINT NOT NULL DEFAULT 0,
        newprogrammeid BIGINT NOT NULL DEFAULT 0,
        newcurriculumid BIGINT NOT NULL DEFAULT 0,
        newcurriculumyearid BIGINT NOT NULL DEFAULT 0,
        newcurriculumsemesterid BIGINT NOT NULL DEFAULT 0,
        newacademicyearid BIGINT NOT NULL DEFAULT 0,
        newsemesterid BIGINT NOT NULL DEFAULT 0,
        newacademicstatus VARCHAR(32) NOT NULL DEFAULT '',
        effectivedate BIGINT NOT NULL DEFAULT 0,
        expectedreturndate BIGINT NOT NULL DEFAULT 0,
        reason TEXT,
        notes TEXT,
        approvalstatus VARCHAR(32) NOT NULL DEFAULT 'recorded',
        approvedby BIGINT NOT NULL DEFAULT 0,
        enrolmentpolicy VARCHAR(40) NOT NULL DEFAULT 'preserve',
        enrolmentresult TEXT,
        createdby BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        KEY userid_idx (userid),
        KEY actiontype_idx (actiontype),
        KEY effectivedate_idx (effectivedate),
        KEY userid_action_idx (userid, actiontype)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 26. local_btiportal_enrol_ops (2026080304)
$tables['local_btiportal_enrol_ops'] = "
    CREATE TABLE {$prefix}local_btiportal_enrol_ops (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        academicyearid BIGINT NOT NULL DEFAULT 0,
        semesterid BIGINT NOT NULL DEFAULT 0,
        operationtype VARCHAR(64) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'previewed',
        summary LONGTEXT,
        initiatedby BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        KEY userid_idx (userid),
        KEY operationtype_idx (operationtype),
        KEY academicyearid_idx (academicyearid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 27. local_btiportal_elective_group (2026080305)
$tables['local_btiportal_elective_group'] = "
    CREATE TABLE {$prefix}local_btiportal_elective_group (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        curriculumid BIGINT NOT NULL,
        yearofstudy BIGINT NOT NULL DEFAULT 0,
        semestersequence BIGINT NOT NULL DEFAULT 0,
        code VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description LONGTEXT,
        minselect BIGINT NOT NULL DEFAULT 1,
        maxselect BIGINT NOT NULL DEFAULT 1,
        mincredits DECIMAL(10,2) NOT NULL DEFAULT 0,
        maxcredits DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY elective_group_code_uq (curriculumid, yearofstudy, semestersequence, code),
        KEY curriculumid_idx (curriculumid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 28. local_btiportal_elective_group_unit (2026080305)
$tables['local_btiportal_elective_group_unit'] = "
    CREATE TABLE {$prefix}local_btiportal_elective_group_unit (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        electivegroupid BIGINT NOT NULL,
        courseunitid BIGINT NOT NULL,
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY elective_group_unit_uq (electivegroupid, courseunitid),
        KEY electivegroupid_idx (electivegroupid),
        KEY courseunitid_idx (courseunitid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 29. local_btiportal_student_elective_sel (2026080305)
$tables['local_btiportal_student_elective_sel'] = "
    CREATE TABLE {$prefix}local_btiportal_student_elective_sel (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        electivegroupid BIGINT NOT NULL,
        courseunitid BIGINT NOT NULL,
        academicyearid BIGINT NOT NULL DEFAULT 0,
        semesterid BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'selected',
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY student_elective_sel_uq (userid, electivegroupid, courseunitid, academicyearid, semesterid),
        KEY userid_idx (userid),
        KEY electivegroupid_idx (electivegroupid),
        KEY status_idx (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 30. local_btiportal_progression_rule (2026080305)
$tables['local_btiportal_progression_rule'] = "
    CREATE TABLE {$prefix}local_btiportal_progression_rule (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        curriculumid BIGINT NOT NULL,
        programmeid BIGINT NOT NULL DEFAULT 0,
        academicyearid BIGINT NOT NULL DEFAULT 0,
        curriculumyearid BIGINT NOT NULL DEFAULT 0,
        curriculumsemesterid BIGINT NOT NULL DEFAULT 0,
        actiontype VARCHAR(64) NOT NULL,
        mincompletedunits BIGINT NOT NULL DEFAULT 0,
        maxfailedunits BIGINT NOT NULL DEFAULT 999,
        requiremanualapproval TINYINT NOT NULL DEFAULT 1,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        version BIGINT NOT NULL DEFAULT 1,
        notes LONGTEXT,
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        KEY curriculumid_idx (curriculumid),
        KEY actiontype_idx (actiontype),
        KEY status_idx (status),
        KEY version_idx (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 31. local_btiportal_timetable (2026080306 + 2026080504 meetinglink + 2026081300 zoom fields)
$tables['local_btiportal_timetable'] = "
    CREATE TABLE {$prefix}local_btiportal_timetable (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        departmentid BIGINT NOT NULL,
        programmeid BIGINT NOT NULL DEFAULT 0,
        courseunitid BIGINT NOT NULL DEFAULT 0,
        moodlecourseid BIGINT NOT NULL DEFAULT 0,
        lecturerid BIGINT NOT NULL DEFAULT 0,
        academicyearid BIGINT NOT NULL DEFAULT 0,
        semesterid BIGINT NOT NULL DEFAULT 0,
        sessiondate BIGINT NOT NULL,
        starttime BIGINT NOT NULL,
        endtime BIGINT NOT NULL,
        room VARCHAR(128) NOT NULL DEFAULT '',
        deliverymode VARCHAR(32) NOT NULL DEFAULT 'on_campus',
        recurrence VARCHAR(64) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        notes LONGTEXT,
        meetinglink VARCHAR(512) NOT NULL DEFAULT '',
        eventid BIGINT NOT NULL DEFAULT 0,
        overrideby BIGINT NOT NULL DEFAULT 0,
        overridetime BIGINT NOT NULL DEFAULT 0,
        overridereason VARCHAR(255) NOT NULL DEFAULT '',
        zoommeetingid VARCHAR(64) NOT NULL DEFAULT '',
        zoomcmid BIGINT NOT NULL DEFAULT 0,
        zoomstatus VARCHAR(32) NOT NULL DEFAULT 'none',
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        KEY departmentid_idx (departmentid),
        KEY lecturerid_idx (lecturerid),
        KEY courseunitid_idx (courseunitid),
        KEY sessiondate_idx (sessiondate),
        KEY status_idx (status),
        KEY moodlecourseid_idx (moodlecourseid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 32. local_btiportal_notification (2026080306)
$tables['local_btiportal_notification'] = "
    CREATE TABLE {$prefix}local_btiportal_notification (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        authorid BIGINT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        body LONGTEXT,
        scope VARCHAR(64) NOT NULL DEFAULT 'department',
        departmentid BIGINT NOT NULL DEFAULT 0,
        schoolid BIGINT NOT NULL DEFAULT 0,
        programmeid BIGINT NOT NULL DEFAULT 0,
        courseunitid BIGINT NOT NULL DEFAULT 0,
        moodlecourseid BIGINT NOT NULL DEFAULT 0,
        targetjson LONGTEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        timescheduled BIGINT NOT NULL DEFAULT 0,
        timesent BIGINT NOT NULL DEFAULT 0,
        deliverystatus VARCHAR(64) NOT NULL DEFAULT 'not_available',
        deliverydetail VARCHAR(255) NOT NULL DEFAULT '',
        usermodified BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        KEY departmentid_idx (departmentid),
        KEY status_idx (status),
        KEY authorid_idx (authorid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 33. local_btiportal_op_audit (2026080306)
$tables['local_btiportal_op_audit'] = "
    CREATE TABLE {$prefix}local_btiportal_op_audit (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        actorid BIGINT NOT NULL DEFAULT 0,
        actorrole VARCHAR(64) NOT NULL DEFAULT '',
        action VARCHAR(64) NOT NULL,
        entitytype VARCHAR(64) NOT NULL DEFAULT '',
        entityid BIGINT NOT NULL DEFAULT 0,
        departmentid BIGINT NOT NULL DEFAULT 0,
        beforestate LONGTEXT,
        afterstate LONGTEXT,
        result VARCHAR(32) NOT NULL DEFAULT 'success',
        failurereason VARCHAR(255) NOT NULL DEFAULT '',
        sourcemodule VARCHAR(64) NOT NULL DEFAULT 'local_btiportal',
        timecreated BIGINT NOT NULL,
        KEY actorid_idx (actorid),
        KEY action_idx (action),
        KEY departmentid_idx (departmentid),
        KEY entity_idx (entitytype, entityid),
        KEY timecreated_idx (timecreated)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 34. local_btiportal_course_request (2026080605 — uses xmldb_table API but we replicate as raw DDL)
$tables['local_btiportal_course_request'] = "
    CREATE TABLE {$prefix}local_btiportal_course_request (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL DEFAULT 0,
        requesttype VARCHAR(32) NOT NULL DEFAULT 'deferral',
        fromprogrammeid BIGINT NOT NULL DEFAULT 0,
        targetprogrammeid BIGINT NOT NULL DEFAULT 0,
        reason LONGTEXT NOT NULL,
        paymentconfirmed TINYINT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'submitted',
        adminnotes LONGTEXT,
        reviewedby BIGINT NOT NULL DEFAULT 0,
        timereviewed BIGINT NOT NULL DEFAULT 0,
        timecreated BIGINT NOT NULL DEFAULT 0,
        timemodified BIGINT NOT NULL DEFAULT 0,
        usermodified BIGINT NOT NULL DEFAULT 0,
        KEY userid_status (userid, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 35. local_btiportal_curriculum_unit (2026081303 — uses xmldb_table API)
$tables['local_btiportal_curriculum_unit'] = "
    CREATE TABLE {$prefix}local_btiportal_curriculum_unit (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        curriculumid BIGINT NOT NULL DEFAULT 0,
        courseunitid BIGINT NOT NULL DEFAULT 0,
        yearofstudy INT NOT NULL DEFAULT 1,
        semestersequence INT NOT NULL DEFAULT 1,
        sortorder BIGINT NOT NULL DEFAULT 0,
        unittype VARCHAR(20) NOT NULL DEFAULT 'core',
        isrequired TINYINT NOT NULL DEFAULT 1,
        timecreated BIGINT NOT NULL DEFAULT 0,
        timemodified BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY curr_unit_uq (curriculumid, courseunitid),
        KEY curr_unit_curr (curriculumid),
        KEY curr_unit_unit (courseunitid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 36. local_btiportal_assessment_meta (2026081401)
$tables['local_btiportal_assessment_meta'] = "
    CREATE TABLE {$prefix}local_btiportal_assessment_meta (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        cmid BIGINT NOT NULL,
        courseid BIGINT NOT NULL,
        unitid BIGINT NOT NULL,
        module VARCHAR(20) NOT NULL,
        instanceid BIGINT NOT NULL,
        assessmenttype VARCHAR(32) NOT NULL,
        passmark DECIMAL(10,2) NOT NULL DEFAULT 0,
        requirefullscreen TINYINT NOT NULL DEFAULT 0,
        maxwarnings INT NOT NULL DEFAULT 3,
        warningaction VARCHAR(20) NOT NULL DEFAULT 'submit_flag',
        timecreated BIGINT NOT NULL,
        timemodified BIGINT NOT NULL,
        UNIQUE KEY cmid_uq (cmid),
        KEY courseid_idx (courseid),
        KEY instance_idx (module, instanceid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 37. local_btiportal_exam_event (2026081401)
$tables['local_btiportal_exam_event'] = "
    CREATE TABLE {$prefix}local_btiportal_exam_event (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        quizid BIGINT NOT NULL,
        cmid BIGINT NOT NULL,
        courseid BIGINT NOT NULL,
        attemptid BIGINT NOT NULL DEFAULT 0,
        userid BIGINT NOT NULL,
        eventtype VARCHAR(40) NOT NULL,
        warningcount INT NOT NULL DEFAULT 0,
        details VARCHAR(500) NOT NULL DEFAULT '',
        timecreated BIGINT NOT NULL,
        KEY quizid_idx (quizid),
        KEY attemptid_idx (attemptid),
        KEY userid_idx (userid),
        KEY courseid_idx (courseid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 38. local_btiportal_exam_flag (2026081401)
$tables['local_btiportal_exam_flag'] = "
    CREATE TABLE {$prefix}local_btiportal_exam_flag (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        quizid BIGINT NOT NULL,
        attemptid BIGINT NOT NULL,
        userid BIGINT NOT NULL,
        warningcount INT NOT NULL DEFAULT 0,
        flagged TINYINT NOT NULL DEFAULT 0,
        autosubmitted TINYINT NOT NULL DEFAULT 0,
        lastaction VARCHAR(20) NOT NULL DEFAULT '',
        timemodified BIGINT NOT NULL,
        UNIQUE KEY attemptid_uq (attemptid),
        KEY quizid_idx (quizid),
        KEY userid_idx (userid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// ---------- Run ----------

cli_writeln(sprintf('Checking %d tables...', count($tables)));
cli_writeln('');

$missing = [];
foreach ($tables as $name => $ddl) {
    if ($tableexists($name)) {
        ++$skipped;
    } else {
        $missing[$name] = $ddl;
        cli_writeln("  MISSING: {$prefix}{$name}");
    }
}

cli_writeln('');
cli_writeln(sprintf('Existing: %d | Missing: %d | Total: %d', $skipped, count($missing), count($tables)));

if (empty($missing)) {
    cli_writeln('All tables exist. Nothing to do.');
    exit(0);
}

if ($dryrun) {
    cli_writeln('');
    cli_writeln('Re-run with --execute to create the missing tables.');
    exit(0);
}

cli_writeln('');
cli_writeln('Creating missing tables...');
cli_writeln('');

$errors = [];
foreach ($missing as $name => $ddl) {
    // Double-check right before creation (paranoid guard).
    if ($tableexists($name)) {
        cli_writeln("  SKIP (appeared): {$prefix}{$name}");
        continue;
    }
    try {
        $DB->execute($ddl);
        ++$created;
        cli_writeln("  CREATED: {$prefix}{$name}");
    } catch (\Throwable $e) {
        $errors[] = ['table' => $name, 'error' => $e->getMessage()];
        cli_writeln("  ERROR creating {$prefix}{$name}: " . $e->getMessage());
    }
}

cli_writeln('');
cli_writeln(sprintf('Created: %d | Errors: %d', $created, count($errors)));

if ($errors) {
    cli_writeln('');
    cli_writeln('=== ERRORS (review before proceeding) ===');
    foreach ($errors as $err) {
        cli_writeln("  {$err['table']}: {$err['error']}");
    }
    exit(1);
}

cli_writeln('');
cli_writeln('Done. All missing tables have been created.');
cli_writeln('NOTE: This script did NOT register WS functions, insert seed data, or alter mdl_config_plugins.');
cli_writeln('You may need to re-run service function registration separately if WS calls fail.');
