# Moodle Docker stack

Run from this directory:

```powershell
cd moodle
docker compose up -d --build
```

Moodle: http://localhost:8081  
Mailpit: http://localhost:8025  

This compose stack now also includes a `moodle-cron` sidecar which runs
`admin/cli/cron.php` automatically every 60 seconds. Check it with:

```powershell
docker compose logs -f moodle-cron
```

## patches/

`patches/mod_zoom-shared-host.patch` — BTI shared Zoom host override for `mod_zoom`.  
Re-apply after upgrading the Zoom plugin (see comments inside the patch file).
