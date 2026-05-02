#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TIMESTAMP="$(date +"%Y-%m-%d_%H-%M-%S")"
BACKUP_DIR="$PROJECT_ROOT/backups/$TIMESTAMP"
CONTAINER_NAME="timepoint"
DB_PATH_IN_CONTAINER="/var/www/html/assets/db/timetracking.sqlite"
DB_BACKUP_PATH="$BACKUP_DIR/timetracking.sqlite"

mkdir -p "$BACKUP_DIR"

echo "Erstelle Backup in: $BACKUP_DIR"

docker cp "$CONTAINER_NAME:$DB_PATH_IN_CONTAINER" "$DB_BACKUP_PATH"

if command -v sqlite3 >/dev/null 2>&1; then
  sqlite3 "$DB_BACKUP_PATH" ".output $BACKUP_DIR/audit_log.sql" ".dump audit_log"
  echo "Audit-SQL-Export gespeichert: $BACKUP_DIR/audit_log.sql"
else
  echo "sqlite3 ist lokal nicht installiert. Audit-SQL-Export wurde uebersprungen."
fi

echo "Backup abgeschlossen."
