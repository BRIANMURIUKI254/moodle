#!/bin/sh
set -eu

INTERVAL="${MOODLE_CRON_INTERVAL:-60}"
CRON_SCRIPT="/var/www/html/admin/cli/cron.php"
CONFIG_FILE="/var/www/html/config.php"

echo "[moodle-cron] Starting cron loop with interval ${INTERVAL}s"

while [ ! -f "${CONFIG_FILE}" ]; do
  echo "[moodle-cron] Waiting for ${CONFIG_FILE}..."
  sleep 2
done

while true; do
  echo "[moodle-cron] $(date -u +"%Y-%m-%dT%H:%M:%SZ") running ${CRON_SCRIPT}"
  if php "${CRON_SCRIPT}"; then
    echo "[moodle-cron] cron run finished successfully"
  else
    status=$?
    echo "[moodle-cron] cron run failed with exit code ${status}" >&2
  fi
  sleep "${INTERVAL}"
done
