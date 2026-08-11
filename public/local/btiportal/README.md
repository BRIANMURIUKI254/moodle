# local_btiportal

BTI Portal Moodle plugin — canonical source for version control.

## Deploy to the running Moodle container

Docker mounts `./moodle` → `/var/www/html`. Copy this folder into the live tree:

```powershell
Copy-Item -Recurse -Force `
  "ou-somas-portal/moodle-plugins/local_btiportal/*" `
  "moodle/public/local/btiportal/"
```

Then run upgrade:

```powershell
docker exec moodle-app php /var/www/html/admin/cli/upgrade.php --non-interactive --allow-unstable
```

**Important:** every time you bump `$plugin->version`, add a matching `if ($oldversion < …)` block in `db/upgrade.php` that:
1. Registers any new web service functions onto `bti_portal_api` (Moodle updates `external_functions` from `db/services.php` during upgrade, but does **not** auto-add them to an existing custom service).
2. Creates/alters any new DB tables or columns.

Skipping the upgrade step leaves the portal calling undeclared WS functions, which Moodle surfaces as **"Can't find data record in database"**.

## Why two copies?

- **`ou-somas-portal/moodle-plugins/local_btiportal/`** — tracked in the portal git repo (source of truth).
- **`moodle/public/local/btiportal/`** — runtime path used by the Moodle Docker volume.

Keep them in sync after every plugin change.

## Phase 2 academic model (1.2.0)

Adds Programme / Curriculum / Academic Year / Semester / Course Unit / Prerequisites / Course Unit Teachers tables and WS. Does **not** change the Course Management UI or migrate existing Moodle content.

After copying and upgrading:

```powershell
docker exec -u www-data moodle-app php admin/cli/purge_caches.php
```

Manual API checks: `docs/ACADEMIC_WS_MANUAL_TESTS.md`.

### Known environment limitation — PHP syntax checks

There is **no host `php` on PATH** in the typical Windows/PowerShell workspace. Do **not** rely on `php -l` on the host; it fails with `CommandNotFoundException`.

Lint through the Moodle container against the runtime tree (`/var/www/html/public/local/btiportal`):

```powershell
# After syncing source → moodle/public/local/btiportal/
docker exec moodle-app bash /var/www/html/public/local/btiportal/cli/lint_phase2b.sh
```

Or single-file:

```powershell
docker exec moodle-app php -l /var/www/html/public/local/btiportal/classes/external/create_academic_course_unit.php
```
