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
