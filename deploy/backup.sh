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

mkdir -p "${BACKUP_DIR}"
umask 077

DB_FILE="${BACKUP_DIR}/cth-db-${STAMP}.dump"
FILES_FILE="${BACKUP_DIR}/cth-files-${STAMP}.tar.gz"

echo "[$(date -Is)] backup start"

pg_dump -Fc \
  -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-timberhub}" \
  -f "${DB_FILE}" "${DB_DATABASE:-cameroontimberhub}"

# Uploaded documents, certificates signing key (if stored under storage/app),
# public media. Framework caches / sessions / logs are not backed up.
tar -czf "${FILES_FILE}" -C "${APP_DIR}/storage" \
  --exclude='app/livewire-tmp' \
  --exclude='app/private/livewire-tmp' \
  --exclude='framework' \
  --exclude='logs' \
  app

# Sanity: both artefacts must be non-empty.
[ -s "${DB_FILE}" ] && [ -s "${FILES_FILE}" ] || { echo "backup artefact empty" >&2; exit 1; }

if [ -n "${BACKUP_RCLONE_REMOTE:-}" ]; then
  rclone copy "${DB_FILE}" "${BACKUP_RCLONE_REMOTE}/" && \
  rclone copy "${FILES_FILE}" "${BACKUP_RCLONE_REMOTE}/"
  echo "[$(date -Is)] offsite copy -> ${BACKUP_RCLONE_REMOTE}"
fi

find "${BACKUP_DIR}" -maxdepth 1 -type f \( -name 'cth-db-*.dump' -o -name 'cth-files-*.tar.gz' \) \
  -mtime +"${RETENTION_DAYS}" -delete

echo "[$(date -Is)] backup done: $(du -h "${DB_FILE}" | cut -f1) db, $(du -h "${FILES_FILE}" | cut -f1) files"
