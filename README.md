# TimePoint - Time Tracking / Zeiterfassung

TimePoint is a PHP-based time tracking system for working hours, breaks, holidays, sick days, reports, and administrative management.

TimePoint ist eine PHP-basierte Zeiterfassung für Arbeitszeiten, Pausen, Feiertage, Krankheitstage, Auswertungen und administrative Verwaltung.

## DEMO:
- URL: [https://timepoint.breakpoint-tech.de](https://timepoint.breakpoint-tech.de)
- Username: `admin`
- Password: `timepoint-demo-admin`

---

- Changelog: [CHANGELOG.md](CHANGELOG.md)
- API documentation / API-Dokumentation: `apidoc.html`

## Index

- [🇬🇧 English](#english)
  - [Demo Mode](#demo-mode)
  - [Start and Stop](#start-and-stop)
  - [Database via Docker Compose](#database-via-docker-compose)
  - [PostgreSQL Example](#postgresql-example)
  - [Environment Variables](#environment-variables)
  - [Browser Setup](#browser-setup)
  - [External Database](#external-database)
  - [Database Passwords and Volumes](#database-passwords-and-volumes)
  - [Navicat or Other Database Clients](#navicat-or-other-database-clients)
  - [Dokploy](#dokploy)
  - [Using MariaDB](#using-mariadb)
  - [SQLite Migration](#sqlite-migration)
- [🇩🇪 Deutsch](#deutsch)
  - [Demo-Modus](#demo-modus)
  - [Start und Stopp](#start-und-stopp)
  - [Datenbank per Docker Compose](#datenbank-per-docker-compose)
  - [PostgreSQL Beispiel](#postgresql-beispiel)
  - [Environment-Variablen](#environment-variablen)
  - [Setup im Browser](#setup-im-browser)
  - [Externe Datenbank](#externe-datenbank)
  - [Datenbank-Passwort und Volumes](#datenbank-passwort-und-volumes)
  - [Navicat oder andere Datenbank-Clients](#navicat-oder-andere-datenbank-clients)
  - [Dokploy](#dokploy-de)
  - [MariaDB verwenden](#mariadb-verwenden)
  - [SQLite-Migration](#sqlite-migration-de)

---

<a id="english"></a>

## 🇬🇧 English

### Start and Stop

Start the containers:

```bash
docker compose up -d --build
```

Stop the containers:

```bash
docker compose down
```

### Database via Docker Compose

The default `compose.yaml` starts PostgreSQL, which is the recommended database. MariaDB is supported as an alternative via `compose.mariadb.example.yaml`.

Host, port, database name, user, and password are controlled via environment variables. On the first browser request, TimePoint opens the setup wizard. The database fields are prefilled from these variables, but can be changed in the browser for external databases.

### PostgreSQL Example

A template is available in [.env.example](.env.example):

```bash
cp .env.example .env
```

PostgreSQL example:

```env
TIMEPOINT_DB_DRIVER=pgsql
TIMEPOINT_DB_HOST=timepoint-db
TIMEPOINT_DB_PORT=5432
TIMEPOINT_DB_NAME=timepoint
TIMEPOINT_DB_USER=timepoint
TIMEPOINT_DB_PASSWORD=change-me
TIMEPOINT_DB_ROOT_PASSWORD=change-root-me
```

These variables are required. If a variable is missing or misspelled, `docker compose up` fails instead of silently starting PostgreSQL or MariaDB with a default password.

### Environment Variables

- `TIMEPOINT_DB_DRIVER`: Database type. Use `pgsql` for PostgreSQL or `mysql` for MariaDB.
- `TIMEPOINT_DB_HOST`: Database host. For the bundled Docker database, use `timepoint-db`.
- `TIMEPOINT_DB_PORT`: Database port. PostgreSQL usually uses `5432`, MariaDB usually uses `3306`.
- `TIMEPOINT_DB_NAME`: Database name.
- `TIMEPOINT_DB_USER`: Database user for TimePoint.
- `TIMEPOINT_DB_PASSWORD`: Password for the TimePoint database user.
- `TIMEPOINT_DB_ROOT_PASSWORD`: MariaDB root password. Not relevant for PostgreSQL, but included in the example env file.

### Browser Setup

On the first request, the setup wizard starts. It first tests the database connection. Only after a successful connection test can the first administrator be created directly in the setup wizard.

After setup is saved, the generated configuration is stored in the runtime volume as `config.local.php`. TimePoint will not show the setup again. For a completely new setup configuration, delete the runtime volume or remove `config.local.php`.

### Demo Mode

For public test deployments, use the standalone demo compose file:

```bash
docker compose -f compose.demo.yaml up -d --build
```

The demo is available on port `8080` by default. The demo admin login is controlled through environment variables in `compose.demo.yaml`:

```env
TIMEPOINT_DEMO_ADMIN_USERNAME=admin
TIMEPOINT_DEMO_ADMIN_PASSWORD=timepoint-demo-admin
TIMEPOINT_DEMO_ADMIN_EMAIL=demo-admin@example.com
```

Public demo:

- URL: [https://timepoint.breakpoint-tech.de](https://timepoint.breakpoint-tech.de)
- Username: `admin`
- Password: `timepoint-demo-admin`

When `TIMEPOINT_DEMO_MODE=1` is active, TimePoint writes the Docker database config automatically, creates or repairs the demo admin, and blocks changes to that demo admin account. The regular `compose.yaml` is unchanged and the demo protections stay inactive unless `TIMEPOINT_DEMO_MODE` is enabled.

### External Database

For an external database, the prefilled values can be changed directly in the browser. `TIMEPOINT_DB_HOST=timepoint-db` only applies to the bundled database in the same Compose network. For an external database, enter the external hostname or IP address.

### Database Passwords and Volumes

Docker applies `POSTGRES_PASSWORD` or `MARIADB_PASSWORD` only when the database volume is created for the first time. If the database container was already initialized, the old password remains stored in the volume.

For a fresh installation:

```bash
docker compose down -v
docker compose up -d --build
```

Warning: `down -v` deletes the existing database. If production data already exists, do not use `down -v`; change the database password directly instead.

Set the PostgreSQL password manually:

```bash
docker compose exec timepoint-db psql -U timepoint -d timepoint -c "ALTER USER timepoint WITH PASSWORD 'new-password';"
```

### Navicat or Other Database Clients

External clients use the same credentials:

- Host: IP or hostname of the Docker host, for example the Tailscale IP
- Port: `5432` for PostgreSQL or `3306` for MariaDB
- Database: `TIMEPOINT_DB_NAME`
- User: `TIMEPOINT_DB_USER`
- Password: `TIMEPOINT_DB_PASSWORD`

If `.env` contains:

```env
TIMEPOINT_DB_PASSWORD='my#password'
```

enter only `my#password` in Navicat, without quotes.

To reach the database from Navicat, publish the database port:

```yaml
ports:
  - "5432:5432"
```

Optionally bind the port directly to the Tailscale IP:

```yaml
ports:
  - "100.x.y.z:5432:5432"
```

If Navicat reports `password authentication failed`, the port is reachable, but the database user has a different password than expected.

### Dokploy

For deployments without a `.env` file, for example in Dokploy, the variables must be set as Compose or project environment variables. Runtime-only variables for a single container are not enough because `POSTGRES_PASSWORD`, `POSTGRES_USER`, and `POSTGRES_DB` are substituted directly in `compose.yaml` from `${TIMEPOINT_DB_*}`.

Check which PostgreSQL values reached the database container:

```bash
docker inspect timepoint-db --format '{{range .Config.Env}}{{println .}}{{end}}' | grep POSTGRES_
```

Check which database values the TimePoint container sees:

```bash
docker inspect timepoint --format '{{range .Config.Env}}{{println .}}{{end}}' | grep TIMEPOINT_DB
```

If these values are not what you expect, the variables are set in the wrong place in Dokploy or are not passed to Compose interpolation.

### Using MariaDB

Copy the MariaDB example:

```bash
cp compose.mariadb.example.yaml compose.yaml
```

Then set the values in `.env` or in your deployment:

```env
TIMEPOINT_DB_DRIVER=mysql
TIMEPOINT_DB_HOST=timepoint-db
TIMEPOINT_DB_PORT=3306
TIMEPOINT_DB_NAME=timepoint
TIMEPOINT_DB_USER=timepoint
TIMEPOINT_DB_PASSWORD=change-me
TIMEPOINT_DB_ROOT_PASSWORD=change-root-me
```

Start with MariaDB:

```bash
docker compose up -d --build
```

### SQLite Migration

In the admin area, an existing SQLite database can be imported into PostgreSQL or MariaDB through the browser. The import is designed for the new database structure and converts old SQLite empty values in a PostgreSQL/MariaDB-compatible way.

There is also an optional CLI script:

```bash
php scripts/migrate_sqlite_to_configured_db.php --source=/path/timetracking.sqlite --yes
```

By default, existing TimePoint user passwords are kept when the username or email address matches. To import passwords from SQLite instead:

```bash
php scripts/migrate_sqlite_to_configured_db.php --yes --replace-user-passwords
```

---

<a id="deutsch"></a>

## 🇩🇪 Deutsch

### Start und Stopp

Container starten:

```bash
docker compose up -d --build
```

Container stoppen:

```bash
docker compose down
```

### Datenbank per Docker Compose

Die normale `compose.yaml` startet PostgreSQL als empfohlene Datenbank. MariaDB wird alternativ über `compose.mariadb.example.yaml` unterstützt.

Host, Port, Datenbankname, Benutzer und Passwort werden über Environment-Variablen gesteuert. Beim ersten Browseraufruf öffnet TimePoint das Setup. Die Datenbankfelder werden aus diesen Variablen vorbelegt, können im Browser aber für externe Datenbanken angepasst werden.

### PostgreSQL Beispiel

Eine Vorlage liegt in [.env.example](.env.example):

```bash
cp .env.example .env
```

Beispiel für PostgreSQL:

```env
TIMEPOINT_DB_DRIVER=pgsql
TIMEPOINT_DB_HOST=timepoint-db
TIMEPOINT_DB_PORT=5432
TIMEPOINT_DB_NAME=timepoint
TIMEPOINT_DB_USER=timepoint
TIMEPOINT_DB_PASSWORD=bitte-aendern
TIMEPOINT_DB_ROOT_PASSWORD=bitte-root-aendern
```

Diese Variablen sind Pflicht. Wenn eine Variable fehlt oder falsch geschrieben ist, bricht `docker compose up` ab, statt PostgreSQL oder MariaDB mit einem stillen Standardpasswort zu starten.

### Environment-Variablen

- `TIMEPOINT_DB_DRIVER`: Datenbanktyp. `pgsql` für PostgreSQL, `mysql` für MariaDB.
- `TIMEPOINT_DB_HOST`: Hostname der Datenbank. Bei der mitgelieferten Docker-Datenbank: `timepoint-db`.
- `TIMEPOINT_DB_PORT`: Port der Datenbank. PostgreSQL nutzt meist `5432`, MariaDB meist `3306`.
- `TIMEPOINT_DB_NAME`: Name der Datenbank.
- `TIMEPOINT_DB_USER`: Datenbankbenutzer für TimePoint.
- `TIMEPOINT_DB_PASSWORD`: Passwort für den TimePoint-Datenbankbenutzer.
- `TIMEPOINT_DB_ROOT_PASSWORD`: Root-Passwort für MariaDB. Für PostgreSQL nicht relevant, aber in der Beispiel-ENV enthalten.

### Setup im Browser

Beim ersten Aufruf startet das Setup. Dort wird zuerst die Datenbankverbindung getestet. Erst nach erfolgreichem Test kann der erste Administrator direkt im Setup angelegt werden.

Wenn das Setup gespeichert wurde, liegt die erzeugte Konfiguration im Runtime-Volume unter `config.local.php`. Danach öffnet TimePoint nicht erneut das Setup. Für eine komplett neue Setup-Konfiguration muss das Runtime-Volume gelöscht oder die `config.local.php` entfernt werden.

### Demo-Modus

Für öffentlich erreichbare Testinstallationen gibt es eine separate Demo-Compose-Datei:

```bash
docker compose -f compose.demo.yaml up -d --build
```

Die Demo läuft standardmäßig auf Port `8080`. Die Demo-Admin-Zugangsdaten werden in `compose.demo.yaml` über Environment-Variablen gesteuert:

```env
TIMEPOINT_DEMO_ADMIN_USERNAME=admin
TIMEPOINT_DEMO_ADMIN_PASSWORD=timepoint-demo-admin
TIMEPOINT_DEMO_ADMIN_EMAIL=demo-admin@example.com
```

Öffentliche Demo:

- URL: [https://timepoint.breakpoint-tech.de](https://timepoint.breakpoint-tech.de)
- Benutzername: `admin`
- Passwort: `timepoint-demo-admin`

Wenn `TIMEPOINT_DEMO_MODE=1` aktiv ist, schreibt TimePoint die Docker-Datenbankkonfiguration automatisch, erstellt oder repariert den Demo-Admin und blockiert Änderungen an diesem Demo-Admin-Konto. Die normale `compose.yaml` bleibt unverändert; der Demo-Schutz ist nur aktiv, wenn `TIMEPOINT_DEMO_MODE` gesetzt ist.

### Externe Datenbank

Für eine externe Datenbank können die vorbelegten Werte direkt im Browser überschrieben werden. `TIMEPOINT_DB_HOST=timepoint-db` gilt nur für die mitgelieferte Datenbank im gleichen Compose-Netzwerk. Bei einer externen Datenbank muss dort der externe Hostname oder die IP-Adresse eingetragen werden.

### Datenbank-Passwort und Volumes

Docker wendet `POSTGRES_PASSWORD` beziehungsweise `MARIADB_PASSWORD` nur beim ersten Erstellen des Datenbank-Volumes an. Wenn der Datenbank-Container bereits initialisiert wurde, bleibt das alte Passwort im Volume erhalten.

Für eine frische Installation:

```bash
docker compose down -v
docker compose up -d --build
```

Achtung: `down -v` löscht die bestehende Datenbank. Wenn bereits produktive Daten vorhanden sind, nicht `down -v` nutzen, sondern das Passwort in der Datenbank passend ändern.

PostgreSQL-Passwort manuell setzen:

```bash
docker compose exec timepoint-db psql -U timepoint -d timepoint -c "ALTER USER timepoint WITH PASSWORD 'neues-passwort';"
```

### Navicat oder andere Datenbank-Clients

Externe Clients verwenden dieselben Zugangsdaten:

- Host: IP oder Hostname des Docker-Hosts, zum Beispiel die Tailscale-IP
- Port: `5432` für PostgreSQL oder `3306` für MariaDB
- Datenbank: `TIMEPOINT_DB_NAME`
- Benutzer: `TIMEPOINT_DB_USER`
- Passwort: `TIMEPOINT_DB_PASSWORD`

Wenn in `.env` steht:

```env
TIMEPOINT_DB_PASSWORD='mein#passwort'
```

dann wird in Navicat nur `mein#passwort` eingetragen, ohne Anführungszeichen.

Damit Navicat die Datenbank erreicht, muss der Port nach außen veröffentlicht sein:

```yaml
ports:
  - "5432:5432"
```

Optional kann der Port direkt auf die Tailscale-IP gebunden werden:

```yaml
ports:
  - "100.x.y.z:5432:5432"
```

Wenn Navicat `password authentication failed` meldet, ist der Port erreichbar, aber der Datenbankbenutzer hat ein anderes Passwort als erwartet.

<a id="dokploy-de"></a>

### Dokploy

Bei Deployments ohne `.env` Datei, zum Beispiel in Dokploy, müssen die Variablen als Compose- oder Project-Environment gesetzt werden. Reine Runtime-Environment-Variablen für nur einen Container reichen nicht, weil `POSTGRES_PASSWORD`, `POSTGRES_USER` und `POSTGRES_DB` direkt in `compose.yaml` aus `${TIMEPOINT_DB_*}` ersetzt werden.

Prüfen, welche PostgreSQL-Werte im Datenbankcontainer angekommen sind:

```bash
docker inspect timepoint-db --format '{{range .Config.Env}}{{println .}}{{end}}' | grep POSTGRES_
```

Prüfen, welche Datenbankwerte der TimePoint-Container sieht:

```bash
docker inspect timepoint --format '{{range .Config.Env}}{{println .}}{{end}}' | grep TIMEPOINT_DB
```

Wenn dort nicht die erwarteten Werte stehen, werden die Variablen in Dokploy an der falschen Stelle gesetzt oder nicht an die Compose-Interpolation übergeben.

### MariaDB verwenden

MariaDB-Beispiel kopieren:

```bash
cp compose.mariadb.example.yaml compose.yaml
```

Dann die Werte in `.env` oder im Deployment setzen:

```env
TIMEPOINT_DB_DRIVER=mysql
TIMEPOINT_DB_HOST=timepoint-db
TIMEPOINT_DB_PORT=3306
TIMEPOINT_DB_NAME=timepoint
TIMEPOINT_DB_USER=timepoint
TIMEPOINT_DB_PASSWORD=bitte-aendern
TIMEPOINT_DB_ROOT_PASSWORD=bitte-root-aendern
```

Start mit MariaDB:

```bash
docker compose up -d --build
```

<a id="sqlite-migration-de"></a>

### SQLite-Migration

Im Adminbereich kann eine vorhandene SQLite-Datenbank über den Browser in PostgreSQL oder MariaDB importiert werden. Der Import ist für die neue Datenbankstruktur gedacht und behandelt alte SQLite-Leerwerte passend für PostgreSQL/MariaDB.

Optional gibt es ein CLI-Script:

```bash
php scripts/migrate_sqlite_to_configured_db.php --source=/pfad/timetracking.sqlite --yes
```

Standardmäßig werden bestehende TimePoint-Benutzerpasswörter bei gleichem Benutzername oder gleicher E-Mail behalten. Wer die Passwörter aus SQLite übernehmen möchte:

```bash
php scripts/migrate_sqlite_to_configured_db.php --yes --replace-user-passwords
```
