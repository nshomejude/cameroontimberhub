#!/usr/bin/env bash
#
# Nightly backup for Cameroon Timber Hub: PostgreSQL dump + uploaded files
# (storage/app, excluding caches), with local retention and an optional
# offsite copy via rclone. Run as `timberhub` from cron (see deploy/cron).
#
# Configuration (environment variables, all optional):
#   APP_DIR                 app root      (default /home/timberhub/htdocs/www.cameroontimberhub.com)
#   BACKUP_DIR              destination   (default /home/timberhub/backups)
#   BACKUP_RETENTION_DAYS   local retention in days (default 14)
#   BACKUP_RCLONE_REMOTE    e.g. "s3:timberhub-backups" — if set, each backup
#                           is copied offsite with `rclone copy`
#   CERTIFICATE_SIGNING_KEY_PATH
#                           defaults to the value in $APP_DIR/.env; when the key
#                           lives outside storage/app it is copied next to the
#                           archives as cth-signing-key-<stamp>.json (mode 600)
#   DB_DATABASE / DB_HOST / DB_PORT / DB_USERNAME / PGPASSWORD
#                           default to the values in $APP_DIR/.env
#
# Restore drill: see docs/ops/RUNBOOK.md §4 ("Backups & restore").
set -euo pipefail

APP_DIR="${APP_DIR:-/home/timberhub/htdocs/www.cameroontimberhub.com}"
BACKUP_DIR="${BACKUP_DIR:-/home/timberhub/backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
STAMP="$(date +%F-%H%M)"

# Read a KEY=value from .env (without sourcing it — values may contain spaces).
env_value() {
  local key="$1"
  grep -E "^${key}=" "${APP_DIR}/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

DB_DATABASE="${DB_DATABASE:-$(env_value DB_DATABASE)}"
DB_HOST="${DB_HOST:-$(env_value DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"
DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"
export PGPASSWORD="${PGPASSWORD:-$(env_value DB_PASSWORD)}"
SIGNING_KEY_PATH="${CERTIFICATE_SIGNING_KEY_PATH:-$(env_value CERTIFICATE_SIGNING_KEY_PATH)}"

umask 077
mkdir -p "${BACKUP_DIR}"

DB_FILE="${BACKUP_DIR}/cth-db-${STAMP}.dump"
FILES_FILE="${BACKUP_DIR}/cth-files-${STAMP}.tar.gz"

echo "[$(date -Is)] backup start"

pg_dump -Fc \
  -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-timberhub}" \
  -f "${DB_FILE}" "${DB_DATABASE:-cameroontimberhub}"

# Uploaded documents, certificates signing key (default location
# storage/app/certificates), public media. Only storage/app is archived, so
# storage/framework and storage/logs are never included; the excludes are
# --anchored so a folder that merely happens to be called "logs" inside an
# upload tree is still backed up.
tar -czf "${FILES_FILE}" -C "${APP_DIR}/storage" --anchored \
  --exclude='app/livewire-tmp' \
  --exclude='app/private/livewire-tmp' \
  --exclude='app/logs' \
  app

# Certificate signing key: losing it invalidates every issued certificate's
# verification. If it lives outside storage/app (recommended: a secrets dir),
# copy it alongside the archives (umask 077 -> owner-only).
KEY_FILE=""
case "${SIGNING_KEY_PATH}" in
  "" | "${APP_DIR}/storage/app/"*) ;;
  *)
    if [ -r "${SIGNING_KEY_PATH}" ]; then
      KEY_FILE="${BACKUP_DIR}/cth-signing-key-${STAMP}.json"
      cp "${SIGNING_KEY_PATH}" "${KEY_FILE}"
      chmod 600 "${KEY_FILE}"
    else
      echo "WARNING: CERTIFICATE_SIGNING_KEY_PATH [${SIGNING_KEY_PATH}] not readable — signing key NOT backed up" >&2
    fi
    ;;
esac

# Sanity: both artefacts must be non-empty.
[ -s "${DB_FILE}" ] && [ -s "${FILES_FILE}" ] || { echo "backup artefact empty" >&2; exit 1; }

if [ -n "${BACKUP_RCLONE_REMOTE:-}" ]; then
  rclone copy "${DB_FILE}" "${BACKUP_RCLONE_REMOTE}/" && \
  rclone copy "${FILES_FILE}" "${BACKUP_RCLONE_REMOTE}/"
  if [ -n "${KEY_FILE}" ]; then
    rclone copy "${KEY_FILE}" "${BACKUP_RCLONE_REMOTE}/"
  fi
  echo "[$(date -Is)] offsite copy -> ${BACKUP_RCLONE_REMOTE}"
fi

find "${BACKUP_DIR}" -maxdepth 1 -type f \( -name 'cth-db-*.dump' -o -name 'cth-files-*.tar.gz' -o -name 'cth-signing-key-*.json' \) \
  -mtime +"${RETENTION_DAYS}" -delete

echo "[$(date -Is)] backup done: $(du -h "${DB_FILE}" | cut -f1) db, $(du -h "${FILES_FILE}" | cut -f1) files"
