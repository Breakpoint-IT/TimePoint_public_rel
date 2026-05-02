#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
SCRIPTS_DIR="$PROJECT_ROOT/scripts"

echo "TimePoint Verwaltung"
echo "1 - Start Docker"
echo "2 - Stop Docker"
echo "3 - Restart Docker"
echo "4 - Backup Database and AuditLog"
echo "5 - Exit"
printf "Bitte waehlen: "
read -r CHOICE

case "$CHOICE" in
  1)
    "$SCRIPTS_DIR/start.sh"
    ;;
  2)
    "$SCRIPTS_DIR/stop.sh"
    ;;
  3)
    "$SCRIPTS_DIR/restart.sh"
    ;;
  4)
    "$SCRIPTS_DIR/backup.sh"
    ;;
  5)
    exit 0
    ;;
  *)
    echo "Ungueltige Auswahl."
    exit 1
    ;;
esac
