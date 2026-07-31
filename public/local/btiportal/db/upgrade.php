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

        // Also attach to the plugin's own service if present.
        $localservice = $DB->get_record('external_services', ['shortname' => 'btiportal_local'], '*', IGNORE_MISSING);
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
        // Refresh WS description so create_document_assignment accepts introattachments.
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
        $service = $DB->get_record('external_services', ['shortname' => 'btiportal_local']);
        if ($service && !$DB->record_exists('external_services_functions', [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ])) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $service->id,
                'functionname' => $fn,
            ]);
        }
        // Also attach to the main portal API service if present.
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

    return true;
}
