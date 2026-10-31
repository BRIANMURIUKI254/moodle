<?php
defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_btiportal_upgrade($oldversion) {
    global $DB, $CFG;

    if ($oldversion < 2026071601) {
        $fn = 'local_btiportal_authorize_portal_user';
        $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($service
            && $DB->record_exists('external_functions', ['name' => $fn])
            && !$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])
        ) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026071601, 'local', 'btiportal');
    }

    if ($oldversion < 2026071701) {
        // 1) local_btiportal_unit mapping table.
        $table = $CFG->prefix . 'local_btiportal_unit';
        $exists = $DB->record_exists_sql(
            'SELECT 1
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = ?',
            [$table]
        );

        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    courseid INT NOT NULL,
                    sectionid INT NOT NULL,
                    teacheruserid INT NOT NULL,
                    timecreated INT NOT NULL,
                    UNIQUE KEY unit_course_section (courseid, sectionid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        // 2) Ensure unit mapping function is on the main bti_portal_api service.
        $fn = 'local_btiportal_ensure_unit_teacher';
        $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($service
            && $DB->record_exists('external_functions', ['name' => $fn])
            && !$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])
        ) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026071701, 'local', 'btiportal');
    }

    if ($oldversion < 2026071801) {
        $fn = 'local_btiportal_ensure_unit_teacher';
        $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($service
            && !$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])
        ) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026071801, 'local', 'btiportal');
    }

    if ($oldversion < 2026071901) {
        $fns = [
            'local_btiportal_get_unit_teachers',
            'local_btiportal_get_my_teaching_sections',
        ];
        $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($service) {
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026071901, 'local', 'btiportal');
    }

    if ($oldversion < 2026071902) {
        $fn = 'local_btiportal_change_own_password';
        $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($service
            && !$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])
        ) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026071902, 'local', 'btiportal');
    }

    if ($oldversion < 2026072017) {
        // Register new unit management WS functions on the portal API service.
        // Version bumps 2026072014–2016 shipped code + services.php entries but
        // never ran an upgrade step, so external_functions / bti_portal_api were
        // out of sync and calls failed with "Can't find data record in database".
        $fns = [
            'local_btiportal_remove_unit_teacher',
            'local_btiportal_delete_course_unit',
            'local_btiportal_create_course_unit',
        ];
        $service = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($service) {
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        // Also attach to the plugin's own service if present (shortname is bti_portal_api).
        $localservice = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($localservice) {
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $localservice->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $localservice->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026072017, 'local', 'btiportal');
    }

    if ($oldversion < 2026072018) {
        $fns = [
            'local_btiportal_get_password_policy',
            'local_btiportal_change_own_password',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026072018, 'local', 'btiportal');
    }

    if ($oldversion < 2026072019) {
        $announcements = $CFG->prefix . 'local_btiportal_announcement';
        $recordings = $CFG->prefix . 'local_btiportal_recording';

        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$announcements]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$announcements} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    courseid BIGINT NOT NULL,
                    authorid BIGINT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    body LONGTEXT NOT NULL,
                    eventid BIGINT NOT NULL DEFAULT 0,
                    timecreated BIGINT NOT NULL,
                    KEY courseid_idx (courseid),
                    KEY authorid_idx (authorid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$recordings]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$recordings} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $fns = [
            'local_btiportal_create_course_calendar_events',
            'local_btiportal_publish_announcement',
            'local_btiportal_get_announcements',
            'local_btiportal_publish_recording',
            'local_btiportal_get_recordings',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026072019, 'local', 'btiportal');
    }

    if ($oldversion < 2026072020) {
        $events = $CFG->prefix . 'local_btiportal_event';
        $resources = $CFG->prefix . 'local_btiportal_resource';
        $completions = $CFG->prefix . 'local_btiportal_resource_completion';

        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$events]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$events} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    courseid BIGINT NOT NULL,
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
                    KEY timestart_idx (timestart)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$resources]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$resources} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$completions]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$completions} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    resourceid BIGINT NOT NULL,
                    userid BIGINT NOT NULL,
                    timecreated BIGINT NOT NULL,
                    UNIQUE KEY resource_user_uq (resourceid, userid),
                    KEY userid_idx (userid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $fns = [
            'local_btiportal_publish_event',
            'local_btiportal_get_events',
            'local_btiportal_publish_resource',
            'local_btiportal_get_resources',
            'local_btiportal_set_resource_completion',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026072020, 'local', 'btiportal');
    }

    if ($oldversion < 2026072021) {
        $certs = $CFG->prefix . 'local_btiportal_certificate';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$certs]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$certs} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $fns = [
            'local_btiportal_get_certificates',
            'local_btiportal_ensure_certificate',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026072021, 'local', 'btiportal');
    }

    if ($oldversion < 2026072200) {
        $table = $CFG->prefix . 'local_btiportal_unit';
        $col = $DB->get_records_sql("SHOW COLUMNS FROM {$table} LIKE 'zoomcmid'");
        if (!$col) {
            $DB->execute("ALTER TABLE {$table} ADD COLUMN zoomcmid BIGINT NOT NULL DEFAULT 0 AFTER teacheruserid");
        }

        external_update_descriptions('local_btiportal');

        $fn = 'local_btiportal_set_unit_zoom_cmid';
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026072200, 'local', 'btiportal');
    }

    if ($oldversion < 2026072300) {
        $table = $CFG->prefix . 'local_btiportal_unit_gradeitem';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_get_unit_gradebook',
            'local_btiportal_submit_unit_grade',
            'local_btiportal_map_unit_grade_item',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026072300, 'local', 'btiportal');
    }

    if ($oldversion < 2026072301) {
        external_update_descriptions('local_btiportal');

        $fn = 'local_btiportal_create_document_assignment';
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026072301, 'local', 'btiportal');
    }

    if ($oldversion < 2026072302) {
        // Refresh the web-service definition for the online-text assignment flow.
        external_update_descriptions('local_btiportal');
        upgrade_plugin_savepoint(true, 2026072302, 'local', 'btiportal');
    }

    if ($oldversion < 2026072303) {
        $table = $CFG->prefix . 'local_btiportal_assignment_meta';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_get_assignment_meta',
            'local_btiportal_create_document_assignment',
            'local_btiportal_get_unit_gradebook',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026072303, 'local', 'btiportal');
    }

    if ($oldversion < 2026072304) {
        // Refresh get_unit_gradebook returns: groups + combinedaverages.
        external_update_descriptions('local_btiportal');
        upgrade_plugin_savepoint(true, 2026072304, 'local', 'btiportal');
    }

    if ($oldversion < 2026072500) {
        external_update_descriptions('local_btiportal');
        $fns = ['local_btiportal_save_portal_image'];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026072500, 'local', 'btiportal');
    }

    if ($oldversion < 2026072700) {
        // Register course_deleted observer (events.php) — no schema change.
        upgrade_plugin_savepoint(true, 2026072700, 'local', 'btiportal');
    }

    if ($oldversion < 2026072701) {
        // Premium certificate PDF renderer (certificate_pdf_helper) — no schema change.
        upgrade_plugin_savepoint(true, 2026072701, 'local', 'btiportal');
    }

    if ($oldversion < 2026072702) {
        // Certificate QR → portal /verify/certificate with real logo PDF.
        upgrade_plugin_savepoint(true, 2026072702, 'local', 'btiportal');
    }

    if ($oldversion < 2026072703) {
        $fn = 'local_btiportal_email_temporary_password';
        if (!$DB->record_exists('external_functions', ['name' => $fn])) {
            // services.php is re-read on upgrade; ensure function is linked to our service.
        }
        // btiportal_local was the old shortname; the canonical shortname is bti_portal_api.
        $apiservice = $DB->get_record('external_services', ['shortname' => 'bti_portal_api']);
        if ($apiservice && !$DB->record_exists('external_services_functions', [
            'externalserviceid' => $apiservice->id,
            'functionname' => $fn,
        ])) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $apiservice->id,
                'functionname' => $fn,
            ]);
        }
        upgrade_plugin_savepoint(true, 2026072703, 'local', 'btiportal');
    }

    if ($oldversion < 2026072800) {
        $table = $CFG->prefix . 'local_btiportal_inactive_alert';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        upgrade_plugin_savepoint(true, 2026072800, 'local', 'btiportal');
    }

    // ===== Phase 2 academic course data model (1.2.0) =====

    if ($oldversion < 2026080201) {
        $table = $CFG->prefix . 'local_btiportal_programme';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(50) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description LONGTEXT,
                    departmentid BIGINT NOT NULL DEFAULT 0,
                    moodlecategoryid BIGINT NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    timecreated BIGINT NOT NULL,
                    timemodified BIGINT NOT NULL,
                    UNIQUE KEY programme_code_uq (code),
                    KEY status_idx (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_curriculum';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    programmeid BIGINT NOT NULL,
                    code VARCHAR(50) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    version VARCHAR(50) NOT NULL,
                    description LONGTEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'draft',
                    effectivefrom BIGINT NOT NULL DEFAULT 0,
                    effectiveto BIGINT NOT NULL DEFAULT 0,
                    timecreated BIGINT NOT NULL,
                    timemodified BIGINT NOT NULL,
                    UNIQUE KEY curriculum_prog_version_uq (programmeid, version),
                    KEY programmeid_idx (programmeid),
                    KEY status_idx (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        upgrade_plugin_savepoint(true, 2026080201, 'local', 'btiportal');
    }

    if ($oldversion < 2026080202) {
        $table = $CFG->prefix . 'local_btiportal_academic_year';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_semester';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        upgrade_plugin_savepoint(true, 2026080202, 'local', 'btiportal');
    }

    if ($oldversion < 2026080203) {
        $table = $CFG->prefix . 'local_btiportal_course_unit';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                    timecreated BIGINT NOT NULL,
                    timemodified BIGINT NOT NULL,
                    createdby BIGINT NOT NULL DEFAULT 0,
                    modifiedby BIGINT NOT NULL DEFAULT 0,
                    UNIQUE KEY course_unit_map_uq (curriculumid, courseid, sectionid),
                    UNIQUE KEY course_unit_code_uq (curriculumid, code),
                    KEY programmeid_idx (programmeid),
                    KEY courseid_idx (courseid),
                    KEY sectionid_idx (sectionid),
                    KEY status_idx (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        upgrade_plugin_savepoint(true, 2026080203, 'local', 'btiportal');
    }

    if ($oldversion < 2026080204) {
        $table = $CFG->prefix . 'local_btiportal_course_unit_prerequisite';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    courseunitid BIGINT NOT NULL,
                    prerequisitecourseunitid BIGINT NOT NULL,
                    timecreated BIGINT NOT NULL,
                    createdby BIGINT NOT NULL DEFAULT 0,
                    UNIQUE KEY course_unit_prereq_uq (courseunitid, prerequisitecourseunitid),
                    KEY courseunitid_idx (courseunitid),
                    KEY prerequisitecourseunitid_idx (prerequisitecourseunitid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_course_unit_teacher';
        $exists = $DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if (!$exists) {
            $DB->execute("
                CREATE TABLE {$table} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    courseunitid BIGINT NOT NULL,
                    userid BIGINT NOT NULL,
                    teachingrole VARCHAR(40) NOT NULL,
                    isprimary TINYINT(1) NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    assignedby BIGINT NOT NULL DEFAULT 0,
                    timeassigned BIGINT NOT NULL,
                    timeremoved BIGINT NOT NULL DEFAULT 0,
                    KEY courseunitid_idx (courseunitid),
                    KEY userid_idx (userid),
                    KEY status_idx (status),
                    KEY teachingrole_idx (teachingrole)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        upgrade_plugin_savepoint(true, 2026080204, 'local', 'btiportal');
    }

    if ($oldversion < 2026080205) {
        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_create_programme',
            'local_btiportal_update_programme',
            'local_btiportal_get_programme',
            'local_btiportal_list_programmes',
            'local_btiportal_create_curriculum',
            'local_btiportal_update_curriculum',
            'local_btiportal_get_curriculum',
            'local_btiportal_list_curricula',
            'local_btiportal_create_academic_year',
            'local_btiportal_update_academic_year',
            'local_btiportal_get_academic_year',
            'local_btiportal_list_academic_years',
            'local_btiportal_create_semester',
            'local_btiportal_update_semester',
            'local_btiportal_get_semester',
            'local_btiportal_list_semesters',
            'local_btiportal_create_academic_course_unit',
            'local_btiportal_update_academic_course_unit',
            'local_btiportal_get_academic_course_unit',
            'local_btiportal_get_course_units_by_moodle_course',
            'local_btiportal_archive_academic_course_unit',
            'local_btiportal_add_course_unit_prerequisite',
            'local_btiportal_remove_course_unit_prerequisite',
            'local_btiportal_get_course_unit_prerequisites',
            'local_btiportal_assign_course_unit_teacher',
            'local_btiportal_update_course_unit_teacher',
            'local_btiportal_remove_course_unit_teacher',
            'local_btiportal_get_course_unit_teachers',
            'local_btiportal_set_primary_course_unit_teacher',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080205, 'local', 'btiportal');
    }

    // ===== Phase 3 academic catalogue (1.3.0) =====

    if ($oldversion < 2026080301) {
        $tableexists = function (string $table) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        };
        $columnexists = function (string $table, string $column) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
        };

        $table = $CFG->prefix . 'local_btiportal_school';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_department';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        // Additive programme columns; existing Phase 2 rows keep their data.
        $table = $CFG->prefix . 'local_btiportal_programme';
        if ($tableexists($table)) {
            if (!$columnexists($table, 'awardtype')) {
                $DB->execute("ALTER TABLE {$table} ADD COLUMN awardtype VARCHAR(32) NOT NULL DEFAULT 'bachelor'");
            }
            if (!$columnexists($table, 'duration')) {
                $DB->execute("ALTER TABLE {$table} ADD COLUMN duration DECIMAL(5,1) NOT NULL DEFAULT 0");
            }
            if (!$columnexists($table, 'durationunit')) {
                $DB->execute("ALTER TABLE {$table} ADD COLUMN durationunit VARCHAR(16) NOT NULL DEFAULT 'years'");
            }
            if (!$columnexists($table, 'usermodified')) {
                $DB->execute("ALTER TABLE {$table} ADD COLUMN usermodified BIGINT NOT NULL DEFAULT 0");
            }
        }

        $table = $CFG->prefix . 'local_btiportal_curriculum_year';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_curriculum_semester';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_lecturer_dept';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    userid BIGINT NOT NULL,
                    departmentid BIGINT NOT NULL,
                    membershiprole VARCHAR(64) NOT NULL DEFAULT 'member',
                    isprimary TINYINT(1) NOT NULL DEFAULT 0,
                    status VARCHAR(32) NOT NULL DEFAULT 'active',
                    timecreated BIGINT NOT NULL,
                    timemodified BIGINT NOT NULL,
                    usermodified BIGINT NOT NULL DEFAULT 0,
                    UNIQUE KEY lecturer_dept_uq (userid, departmentid),
                    KEY userid_idx (userid),
                    KEY departmentid_idx (departmentid),
                    KEY status_idx (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_student_profile';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                    timecreated BIGINT NOT NULL,
                    timemodified BIGINT NOT NULL,
                    usermodified BIGINT NOT NULL DEFAULT 0,
                    UNIQUE KEY student_profile_user_uq (userid),
                    KEY programmeid_idx (programmeid),
                    KEY curriculumid_idx (curriculumid),
                    KEY academicyearid_idx (academicyearid),
                    KEY status_idx (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $table = $CFG->prefix . 'local_btiportal_staff_org';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_list_schools',
            'local_btiportal_get_school',
            'local_btiportal_create_school',
            'local_btiportal_update_school',
            'local_btiportal_list_departments',
            'local_btiportal_get_department',
            'local_btiportal_create_department',
            'local_btiportal_update_department',
            'local_btiportal_list_curriculum_years',
            'local_btiportal_create_curriculum_year',
            'local_btiportal_update_curriculum_year',
            'local_btiportal_list_curriculum_semesters',
            'local_btiportal_create_curriculum_semester',
            'local_btiportal_update_curriculum_semester',
            'local_btiportal_list_lecturer_departments',
            'local_btiportal_assign_lecturer_department',
            'local_btiportal_update_lecturer_department',
            'local_btiportal_remove_lecturer_department',
            'local_btiportal_get_student_academic_profile',
            'local_btiportal_set_student_academic_profile',
            'local_btiportal_list_programme_students',
            'local_btiportal_list_staff_org_assignments',
            'local_btiportal_assign_staff_org',
            'local_btiportal_update_staff_org',
            'local_btiportal_remove_staff_org',
            'local_btiportal_get_admin_academic_dashboard',
            'local_btiportal_list_academic_course_units',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080301, 'local', 'btiportal');
    }

    if ($oldversion < 2026080302) {
        // Refresh external service descriptions after academic dashboard / calendar integrity updates.
        external_update_descriptions('local_btiportal');
        upgrade_plugin_savepoint(true, 2026080302, 'local', 'btiportal');
    }

    if ($oldversion < 2026080303) {
        // Phase 25: student academic lifecycle history (placement transitions only; identity stays in Moodle).
        $tableexists = function (string $table) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        };

        $table = $CFG->prefix . 'local_btiportal_student_acad_hist';
        if (!$tableexists($table)) {
            $DB->execute("
                CREATE TABLE {$table} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_get_student_academic_history',
            'local_btiportal_update_student_academic_placement',
            'local_btiportal_defer_student',
            'local_btiportal_return_student_from_deferral',
            'local_btiportal_transfer_student_programme',
            'local_btiportal_change_student_programme',
            'local_btiportal_withdraw_student',
            'local_btiportal_readmit_student',
            'local_btiportal_preview_enrolment_transition',
            'local_btiportal_list_lecturer_course_units',
            'local_btiportal_assign_lecturer_course_unit',
            'local_btiportal_update_lecturer_course_unit',
            'local_btiportal_remove_lecturer_course_unit',
            'local_btiportal_get_lecturer_workload',
            'local_btiportal_list_lecturer_moodle_courses',
            'local_btiportal_assign_lecturer_moodle_course',
            'local_btiportal_remove_lecturer_moodle_course',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080303, 'local', 'btiportal');
    }

    if ($oldversion < 2026080304) {
        // Phase 26: period fields on course unit teachers + enrolment operation history.
        $tableexists = function (string $table) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        };
        $columnexists = function (string $table, string $column) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
        };

        $teachertable = $CFG->prefix . 'local_btiportal_course_unit_teacher';
        if ($tableexists($teachertable) && !$columnexists($teachertable, 'academicyearid')) {
            $DB->execute("ALTER TABLE {$teachertable}
                ADD COLUMN academicyearid BIGINT NOT NULL DEFAULT 0 AFTER status,
                ADD COLUMN semesterid BIGINT NOT NULL DEFAULT 0 AFTER academicyearid,
                ADD KEY academicyearid_idx (academicyearid),
                ADD KEY semesterid_idx (semesterid)");
        }

        $opstable = $CFG->prefix . 'local_btiportal_enrol_ops';
        if (!$tableexists($opstable)) {
            $DB->execute("
                CREATE TABLE {$opstable} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_get_curriculum_delivery',
            'local_btiportal_list_curriculum_course_units',
            'local_btiportal_map_course_unit_moodle_course',
            'local_btiportal_remove_course_unit_moodle_course',
            'local_btiportal_get_student_delivery_plan',
            'local_btiportal_preview_student_academic_enrolment',
            'local_btiportal_execute_student_academic_enrolment',
            'local_btiportal_preview_student_semester_progression',
            'local_btiportal_progress_student_semester',
            'local_btiportal_preview_student_promotion',
            'local_btiportal_promote_student',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080304, 'local', 'btiportal');
    }

    if ($oldversion < 2026080305) {
        // Phase 27: elective groups/selections, progression rules, WS registration.
        $tableexists = function (string $table) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        };

        $grouptable = $CFG->prefix . 'local_btiportal_elective_group';
        if (!$tableexists($grouptable)) {
            $DB->execute("
                CREATE TABLE {$grouptable} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $groupunit = $CFG->prefix . 'local_btiportal_elective_group_unit';
        if (!$tableexists($groupunit)) {
            $DB->execute("
                CREATE TABLE {$groupunit} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    electivegroupid BIGINT NOT NULL,
                    courseunitid BIGINT NOT NULL,
                    usermodified BIGINT NOT NULL DEFAULT 0,
                    timecreated BIGINT NOT NULL,
                    timemodified BIGINT NOT NULL,
                    UNIQUE KEY elective_group_unit_uq (electivegroupid, courseunitid),
                    KEY electivegroupid_idx (electivegroupid),
                    KEY courseunitid_idx (courseunitid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $seltables = $CFG->prefix . 'local_btiportal_student_elective_sel';
        if (!$tableexists($seltables)) {
            $DB->execute("
                CREATE TABLE {$seltables} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $ruletable = $CFG->prefix . 'local_btiportal_progression_rule';
        if (!$tableexists($ruletable)) {
            $DB->execute("
                CREATE TABLE {$ruletable} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_list_elective_groups',
            'local_btiportal_create_elective_group',
            'local_btiportal_update_elective_group',
            'local_btiportal_add_elective_group_unit',
            'local_btiportal_remove_elective_group_unit',
            'local_btiportal_save_student_elective_selections',
            'local_btiportal_list_student_elective_selections',
            'local_btiportal_preview_delivery_for_placement',
            'local_btiportal_compare_academic_delivery',
            'local_btiportal_reconcile_student_enrolments',
            'local_btiportal_execute_enrolment_reconciliation',
            'local_btiportal_get_progression_eligibility',
            'local_btiportal_create_progression_rule',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080305, 'local', 'btiportal');
    }

    if ($oldversion < 2026080306) {
        // Phase 27 operational management: Department Leader fields, timetable, notifications, audit.
        $tableexists = function (string $table) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        };
        $columnexists = function (string $table, string $column) use ($DB): bool {
            return (bool)$DB->record_exists_sql(
                'SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
        };

        $lectable = $CFG->prefix . 'local_btiportal_lecturer_dept';
        if ($tableexists($lectable) && !$columnexists($lectable, 'isdepartmentleader')) {
            $DB->execute("ALTER TABLE {$lectable}
                ADD COLUMN isdepartmentleader TINYINT(1) NOT NULL DEFAULT 0 AFTER isprimary,
                ADD COLUMN timestart BIGINT NOT NULL DEFAULT 0 AFTER isdepartmentleader,
                ADD COLUMN timeend BIGINT NOT NULL DEFAULT 0 AFTER timestart,
                ADD KEY isdepartmentleader_idx (isdepartmentleader)");
            // Backfill leadership from existing head/deputy_head membership roles.
            $DB->execute("UPDATE {$lectable}
                SET isdepartmentleader = 1, timestart = CASE WHEN timestart = 0 THEN timecreated ELSE timestart END
                WHERE status = 'active' AND membershiprole IN ('head', 'deputy_head')");
        }

        $titable = $CFG->prefix . 'local_btiportal_timetable';
        if (!$tableexists($titable)) {
            $DB->execute("
                CREATE TABLE {$titable} (
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
                    eventid BIGINT NOT NULL DEFAULT 0,
                    overrideby BIGINT NOT NULL DEFAULT 0,
                    overridetime BIGINT NOT NULL DEFAULT 0,
                    overridereason VARCHAR(255) NOT NULL DEFAULT '',
                    usermodified BIGINT NOT NULL DEFAULT 0,
                    timecreated BIGINT NOT NULL,
                    timemodified BIGINT NOT NULL,
                    KEY departmentid_idx (departmentid),
                    KEY lecturerid_idx (lecturerid),
                    KEY courseunitid_idx (courseunitid),
                    KEY sessiondate_idx (sessiondate),
                    KEY status_idx (status),
                    KEY moodlecourseid_idx (moodlecourseid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $notiftable = $CFG->prefix . 'local_btiportal_notification';
        if (!$tableexists($notiftable)) {
            $DB->execute("
                CREATE TABLE {$notiftable} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $auditable = $CFG->prefix . 'local_btiportal_op_audit';
        if (!$tableexists($auditable)) {
            $DB->execute("
                CREATE TABLE {$auditable} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        // Optional department scope on existing portal events/announcements.
        $eventtable = $CFG->prefix . 'local_btiportal_event';
        if ($tableexists($eventtable) && !$columnexists($eventtable, 'departmentid')) {
            $DB->execute("ALTER TABLE {$eventtable}
                ADD COLUMN departmentid BIGINT NOT NULL DEFAULT 0 AFTER courseid,
                ADD COLUMN scope VARCHAR(32) NOT NULL DEFAULT 'course' AFTER departmentid,
                ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'published' AFTER scope,
                ADD KEY departmentid_idx (departmentid)");
        }
        $anntable = $CFG->prefix . 'local_btiportal_announcement';
        if ($tableexists($anntable) && !$columnexists($anntable, 'departmentid')) {
            $DB->execute("ALTER TABLE {$anntable}
                ADD COLUMN departmentid BIGINT NOT NULL DEFAULT 0 AFTER courseid,
                ADD COLUMN scope VARCHAR(32) NOT NULL DEFAULT 'course' AFTER departmentid,
                ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'published' AFTER scope,
                ADD COLUMN timepublish BIGINT NOT NULL DEFAULT 0 AFTER status,
                ADD COLUMN timeexpire BIGINT NOT NULL DEFAULT 0 AFTER timepublish,
                ADD KEY departmentid_idx (departmentid)");
        }

        update_capabilities('local_btiportal');
        external_update_descriptions('local_btiportal');

        $fns = [
            'local_btiportal_list_timetable_entries',
            'local_btiportal_create_timetable_entry',
            'local_btiportal_update_timetable_entry',
            'local_btiportal_cancel_timetable_entry',
            'local_btiportal_list_operational_audit',
            'local_btiportal_get_operational_reports',
            'local_btiportal_list_operational_notifications',
            'local_btiportal_create_operational_notification',
            'local_btiportal_send_operational_notification',
            'local_btiportal_cancel_operational_notification',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080306, 'local', 'btiportal');
    }

    if ($oldversion < 2026080307) {
        $fns = ['local_btiportal_delete_moodle_course'];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080307, 'local', 'btiportal');
    }

    if ($oldversion < 2026080501) {
        $fns = [
            'local_btiportal_get_my_lecturer_workspace',
            'local_btiportal_list_my_timetable_entries',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080501, 'local', 'btiportal');
    }

    if ($oldversion < 2026080502) {
        // Lecturer self-read WS must not require system viewacademic (course teachers lack it).
        $fns = [
            'local_btiportal_get_my_lecturer_workspace',
            'local_btiportal_list_my_timetable_entries',
        ];
        foreach ($fns as $fn) {
            if ($DB->record_exists('external_functions', ['name' => $fn])) {
                $DB->set_field('external_functions', 'capabilities', '', ['name' => $fn]);
            }
        }

        upgrade_plugin_savepoint(true, 2026080502, 'local', 'btiportal');
    }

    if ($oldversion < 2026080503) {
        // Student Portal self-scoped web services (no system viewacademic required).
        $fns = [
            'local_btiportal_get_my_student_workspace',
            'local_btiportal_get_my_student_academic_profile',
            'local_btiportal_get_my_student_delivery_plan',
            'local_btiportal_get_my_student_academic_history',
            'local_btiportal_get_my_progression_eligibility',
            'local_btiportal_list_my_elective_groups',
            'local_btiportal_list_my_student_elective_selections',
            'local_btiportal_save_my_student_elective_selections',
            'local_btiportal_list_my_student_timetable_entries',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        foreach ($fns as $fn) {
            if ($DB->record_exists('external_functions', ['name' => $fn])) {
                $DB->set_field('external_functions', 'capabilities', '', ['name' => $fn]);
            }
        }

        upgrade_plugin_savepoint(true, 2026080503, 'local', 'btiportal');
    }

    if ($oldversion < 2026080504) {
        $tableexists = static function (string $table) use ($DB): bool {
            return $DB->record_exists_sql(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        };
        $titable = $CFG->prefix . 'local_btiportal_timetable';
        if ($tableexists($titable)) {
            $col = $DB->get_records_sql("SHOW COLUMNS FROM {$titable} LIKE 'meetinglink'");
            if (!$col) {
                $DB->execute("ALTER TABLE {$titable} ADD COLUMN meetinglink VARCHAR(512) NOT NULL DEFAULT '' AFTER notes");
            }
        }
        upgrade_plugin_savepoint(true, 2026080504, 'local', 'btiportal');
    }

    if ($oldversion < 2026080505) {
        $fns = [
            'local_btiportal_delete_school',
            'local_btiportal_delete_department',
            'local_btiportal_delete_programme',
            'local_btiportal_delete_curriculum',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026080505, 'local', 'btiportal');
    }

    if ($oldversion < 2026080601) {
        $fns = [
            'local_btiportal_lookup_university_identity',
            'local_btiportal_ensure_force_password_change',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026080601, 'local', 'btiportal');
    }

    if ($oldversion < 2026080602) {
        $table = $CFG->prefix . 'local_btiportal_student_profile';
        $columns = [
            'entrytype' => "VARCHAR(32) DEFAULT ''",
            'studymode' => "VARCHAR(32) DEFAULT ''",
            'deliverymode' => "VARCHAR(32) DEFAULT ''",
            'campus' => "VARCHAR(128) DEFAULT ''",
            'intake' => "VARCHAR(128) DEFAULT ''",
            'scholarship' => "VARCHAR(255) DEFAULT ''",
            'sponsor' => "VARCHAR(255) DEFAULT ''",
        ];
        $after = 'status';
        foreach ($columns as $name => $definition) {
            $col = $DB->get_records_sql("SHOW COLUMNS FROM {$table} LIKE '{$name}'");
            if (!$col) {
                $DB->execute("ALTER TABLE {$table} ADD COLUMN {$name} {$definition} AFTER {$after}");
            }
            $after = $name;
        }

        external_update_descriptions('local_btiportal');

        $fn = 'local_btiportal_save_student_registry_details';
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026080602, 'local', 'btiportal');
    }

    if ($oldversion < 2026080603) {
        external_update_descriptions('local_btiportal');
        $fn = 'local_btiportal_get_student_registry_details';
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026080603, 'local', 'btiportal');
    }

    if ($oldversion < 2026080604) {
        // Seed default progression rules for curricula that have none (clears rules_not_configured).
        if ($DB->get_manager()->table_exists('local_btiportal_progression_rule')
            && $DB->get_manager()->table_exists('local_btiportal_curriculum')) {
            $curricula = $DB->get_records('local_btiportal_curriculum', null, '', 'id, programmeid');
            foreach ($curricula as $curriculum) {
                foreach (['semester_progression', 'promotion'] as $actiontype) {
                    $exists = $DB->record_exists('local_btiportal_progression_rule', [
                        'curriculumid' => (int)$curriculum->id,
                        'actiontype' => $actiontype,
                        'status' => 'active',
                    ]);
                    if ($exists) {
                        continue;
                    }
                    $now = time();
                    $DB->insert_record('local_btiportal_progression_rule', (object)[
                        'curriculumid' => (int)$curriculum->id,
                        'programmeid' => (int)$curriculum->programmeid,
                        'academicyearid' => 0,
                        'curriculumyearid' => 0,
                        'curriculumsemesterid' => 0,
                        'actiontype' => $actiontype,
                        'mincompletedunits' => 0,
                        'maxfailedunits' => 999,
                        'requiremanualapproval' => 0,
                        'status' => 'active',
                        'version' => 1,
                        'notes' => 'Default rule seeded by upgrade 1.9.6',
                        'usermodified' => 0,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }

        // Backfill missing curriculum/institutional semesters on student profiles.
        if ($DB->get_manager()->table_exists('local_btiportal_student_profile')) {
            $profiles = $DB->get_records_select(
                'local_btiportal_student_profile',
                '(curriculumyearid > 0 AND curriculumsemesterid = 0) OR (academicyearid > 0 AND semesterid = 0)'
            );
            foreach ($profiles as $profile) {
                $arr = (array)$profile;
                $resolved = \local_btiportal\student_delivery_plan_helper::resolve_placement_gaps($arr, true);
                unset($resolved);
            }
        }

        upgrade_plugin_savepoint(true, 2026080604, 'local', 'btiportal');
    }

    if ($oldversion < 2026080605) {
        $dbman = $DB->get_manager();

        if ($dbman->table_exists('local_btiportal_programme')) {
            $table = new xmldb_table('local_btiportal_programme');
            $field = new xmldb_field('imageurl', XMLDB_TYPE_CHAR, '500', null, XMLDB_NOTNULL, null, '', 'durationunit');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        if ($dbman->table_exists('local_btiportal_course_unit')) {
            $table = new xmldb_table('local_btiportal_course_unit');
            $field = new xmldb_field('imageurl', XMLDB_TYPE_CHAR, '500', null, XMLDB_NOTNULL, null, '', 'isrequired');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('local_btiportal_course_request');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('requesttype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'deferral');
            $table->add_field('fromprogrammeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('targetprogrammeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('paymentconfirmed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'submitted');
            $table->add_field('adminnotes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_status', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
            $dbman->create_table($table);
        }

        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_save_catalogue_image',
            'local_btiportal_delete_academic_course_unit',
            'local_btiportal_create_my_course_request',
            'local_btiportal_list_my_course_requests',
            'local_btiportal_confirm_my_course_request_payment',
            'local_btiportal_list_course_requests',
            'local_btiportal_review_course_request',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080605, 'local', 'btiportal');
    }

    if ($oldversion < 2026080606) {
        // 1.9.8 — get_unit_teachers merges academic teachers; create_course_unit reuses topics by name.
        upgrade_plugin_savepoint(true, 2026080606, 'local', 'btiportal');
    }

    if ($oldversion < 2026080607) {
        // 1.9.9 — semester auto-unlock + student unit pathway on workspace.
        upgrade_plugin_savepoint(true, 2026080607, 'local', 'btiportal');
    }

    if ($oldversion < 2026081101) {
        // 1.9.10 — expose saved course/unit cover images to the portal (get_portal_images).
        external_update_descriptions('local_btiportal');
        $fns = ['local_btiportal_get_portal_images'];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081101, 'local', 'btiportal');
    }

    if ($oldversion < 2026081300) {
        $dbman = $DB->get_manager();
        $tt = new xmldb_table('local_btiportal_timetable');
        if ($dbman->table_exists($tt)) {
            $field = new xmldb_field('zoommeetingid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            if (!$dbman->field_exists($tt, $field)) {
                $dbman->add_field($tt, $field);
            }
            $field = new xmldb_field('zoomcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            if (!$dbman->field_exists($tt, $field)) {
                $dbman->add_field($tt, $field);
            }
            $field = new xmldb_field('zoomstatus', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'none');
            if (!$dbman->field_exists($tt, $field)) {
                $dbman->add_field($tt, $field);
            }
        }

        $cu = new xmldb_table('local_btiportal_course_unit');
        if ($dbman->table_exists($cu)) {
            $index = new xmldb_index('course_unit_map_uq', XMLDB_INDEX_UNIQUE, ['curriculumid', 'courseid', 'sectionid']);
            if ($dbman->index_exists($cu, $index)) {
                $dbman->drop_index($cu, $index);
            }
        }

        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_get_course_unit_moodle_diagnostics',
            'local_btiportal_sync_academic_course_unit_moodle',
            'local_btiportal_list_staff_message_contacts',
            'local_btiportal_assert_staff_can_message',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081300, 'local', 'btiportal');
    }

    if ($oldversion < 2026081301) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_preview_academic_course_unit_moodle',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081301, 'local', 'btiportal');
    }

    if ($oldversion < 2026081302) {
        $dbman = $DB->get_manager();
        $cu = new xmldb_table('local_btiportal_course_unit');
        if ($dbman->table_exists($cu)) {
            $field = new xmldb_field('moodleverifiedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'timemodified');
            if (!$dbman->field_exists($cu, $field)) {
                $dbman->add_field($cu, $field);
            }
        }
        external_update_descriptions('local_btiportal');
        upgrade_plugin_savepoint(true, 2026081302, 'local', 'btiportal');
    }

    if ($oldversion < 2026081303) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_btiportal_curriculum_unit');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('curriculumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('courseunitid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('yearofstudy', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, 1);
            $table->add_field('semestersequence', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, 1);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('unittype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'core');
            $table->add_field('isrequired', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 1);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('curr_unit_uq', XMLDB_INDEX_UNIQUE, ['curriculumid', 'courseunitid']);
            $table->add_index('curr_unit_curr', XMLDB_INDEX_NOTUNIQUE, ['curriculumid']);
            $table->add_index('curr_unit_unit', XMLDB_INDEX_NOTUNIQUE, ['courseunitid']);
            $dbman->create_table($table);
        }
        \local_btiportal\curriculum_unit_helper::migrate_from_course_units();
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_list_curriculum_unit_assignments',
            'local_btiportal_save_curriculum_unit_assignments',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081303, 'local', 'btiportal');
    }

    if ($oldversion < 2026081304) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_audit_student_moodle_enrolments',
            'local_btiportal_remove_student_legacy_enrolment',
            'local_btiportal_reconcile_lecturer_moodle_enrolments',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081304, 'local', 'btiportal');
    }

    if ($oldversion < 2026081305) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_ensure_my_pathway_enrolments',
            'local_btiportal_get_my_course_access',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081305, 'local', 'btiportal');
    }

    if ($oldversion < 2026081401) {
        $meta = $CFG->prefix . 'local_btiportal_assessment_meta';
        if (!$DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$meta]
        )) {
            $DB->execute("
                CREATE TABLE {$meta} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $events = $CFG->prefix . 'local_btiportal_exam_event';
        if (!$DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$events]
        )) {
            $DB->execute("
                CREATE TABLE {$events} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        $flags = $CFG->prefix . 'local_btiportal_exam_flag';
        if (!$DB->record_exists_sql(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$flags]
        )) {
            $DB->execute("
                CREATE TABLE {$flags} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_create_quiz_assessment',
            'local_btiportal_get_assessments',
            'local_btiportal_update_assessment',
            'local_btiportal_set_assessment_visibility',
            'local_btiportal_delete_assessment',
            'local_btiportal_duplicate_assessment',
            'local_btiportal_get_assessment_results',
            'local_btiportal_get_quiz_questions',
            'local_btiportal_get_attempt_questions',
            'local_btiportal_log_exam_event',
            'local_btiportal_get_exam_events',
            'local_btiportal_apply_exam_warning',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026081401, 'local', 'btiportal');
    }

    if ($oldversion < 2026081402) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_list_student_directory',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026081402, 'local', 'btiportal');
    }

    if ($oldversion < 2026081403) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_ensure_my_teaching_access',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026081403, 'local', 'btiportal');
    }

    if ($oldversion < 2026081404) {
        // Recurring weekday timetable rules reuse the existing recurrence column
        // (e.g. "Mon,Wed,Fri"). No schema change — refresh WS descriptions only.
        external_update_descriptions('local_btiportal');
        upgrade_plugin_savepoint(true, 2026081404, 'local', 'btiportal');
    }

    if ($oldversion < 2026081801) {
        // Site Administrator portal login: attach user-context WS, then pre-create
        // a permanent bti_portal_api token so login/token.php can return it.
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_get_current_user_context',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        \local_btiportal\service_auth_helper::ensure_site_admins_can_login();
        upgrade_plugin_savepoint(true, 2026081801, 'local', 'btiportal');
    }

    if ($oldversion < 2026081901) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_get_my_portal_role',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081901, 'local', 'btiportal');
    }

    if ($oldversion < 2026081902) {
        // Defensive migration hardening: some installs may not have legacy course_unit.
        upgrade_plugin_savepoint(true, 2026081902, 'local', 'btiportal');
    }

    if ($oldversion < 2026081903) {
        // Fix shortname mismatch: db/services.php declared 'btiportal_local' but the canonical
        // service shortname used by the frontend and service_auth_helper is 'bti_portal_api'.
        // Rename the service record if the wrong shortname still exists in the database.
        $wrong = $DB->get_record('external_services', ['shortname' => 'btiportal_local'], '*', IGNORE_MISSING);
        $correct = $DB->get_record('external_services', ['shortname' => 'bti_portal_api'], '*', IGNORE_MISSING);
        if ($wrong && !$correct) {
            // Rename it in-place — all existing tokens/functions linked to the service id are preserved.
            $DB->set_field('external_services', 'shortname', 'bti_portal_api', ['id' => $wrong->id]);
        }
        upgrade_plugin_savepoint(true, 2026081903, 'local', 'btiportal');
    }

    if ($oldversion < 2026081904) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_send_admission_welcome_email',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026081904, 'local', 'btiportal');
    }

    if ($oldversion < 2026082000) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_send_admission_welcome_email',
            'local_btiportal_preview_admission_number',
            'local_btiportal_create_student_account',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026082000, 'local', 'btiportal');
    }

    if ($oldversion < 2026082100) {
        external_update_descriptions('local_btiportal');
        $fns = [
            'local_btiportal_get_my_enrolled_programme_courses',
        ];
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            foreach ($fns as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026082100, 'local', 'btiportal');
    }

    if ($oldversion < 2026082400) {
        // Align local_btiportal_unit.timecreated with Moodle convention (NOT NULL DEFAULT 0).
        // Does not backfill existing rows; new inserts that omit the column will no longer fail.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_btiportal_unit');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if ($dbman->field_exists($table, $field)) {
                $dbman->change_field_default($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026082400, 'local', 'btiportal');
    }

    if ($oldversion < 2026101500) {
        // Apply the corrected attempt-question state handling in assessment_helper.
        upgrade_plugin_savepoint(true, 2026101500, 'local', 'btiportal');
    }

    if ($oldversion < 2026102900) {
        external_update_descriptions('local_btiportal');
        $fn = 'local_btiportal_get_my_final_exam_attempt_status';
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026102900, 'local', 'btiportal');
    }

    if ($oldversion < 2026102901) {
        if ($DB->get_manager()->table_exists('local_btiportal_assessment_meta')) {
            $cms = $DB->get_records_sql(
                "SELECT cm.id AS cmid, cm.course, cm.instance, cm.idnumber
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  WHERE cm.idnumber LIKE 'bti-final-%'",
                []
            );
            foreach ($cms as $cm) {
                $courseid = (int)$cm->course;
                $quizid = (int)$cm->instance;
                $cmid = (int)$cm->cmid;
                if ($courseid <= SITEID || $quizid <= 0 || $cmid <= 0) {
                    continue;
                }
                if (!$DB->record_exists('quiz', ['id' => $quizid, 'course' => $courseid])) {
                    continue;
                }

                $meta = $DB->get_record(
                    'local_btiportal_assessment_meta',
                    ['cmid' => $cmid],
                    'id,cmid,courseid,unitid,module,instanceid,assessmenttype,passmark,requirefullscreen,maxwarnings,warningaction',
                    IGNORE_MISSING
                );
                $now = time();
                if ($meta) {
                    $meta->courseid = $courseid;
                    $meta->unitid = 0;
                    $meta->module = 'quiz';
                    $meta->instanceid = $quizid;
                    $meta->assessmenttype = 'final';
                    $meta->timemodified = $now;
                    $DB->update_record('local_btiportal_assessment_meta', $meta);
                } else {
                    $DB->insert_record('local_btiportal_assessment_meta', (object)[
                        'cmid' => $cmid,
                        'courseid' => $courseid,
                        'unitid' => 0,
                        'module' => 'quiz',
                        'instanceid' => $quizid,
                        'assessmenttype' => 'final',
                        'passmark' => 0,
                        'requirefullscreen' => 0,
                        'maxwarnings' => 3,
                        'warningaction' => 'submit_flag',
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026102901, 'local', 'btiportal');
    }

    if ($oldversion < 2026103001) {
        external_update_descriptions('local_btiportal');
        $fn = 'local_btiportal_launch_quiz_attempt';
        foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
            if (!$service) {
                continue;
            }
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026103001, 'local', 'btiportal');
    }

    return true;
}
