#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG_FILE="$PROJECT_ROOT/config.local.php"

if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "Keine config.local.php gefunden. Bitte TimePoint zuerst im Browser einrichten."
  exit 1
fi

echo "TimePoint verwendet jetzt PostgreSQL oder MariaDB."
echo "Bitte Backups mit dem passenden Datenbankwerkzeug erstellen:"
echo "- PostgreSQL: pg_dump"
echo "- MariaDB: mariadb-dump oder mysqldump"
echo
echo "Die Zugangsdaten stehen lokal in: $CONFIG_FILE"
