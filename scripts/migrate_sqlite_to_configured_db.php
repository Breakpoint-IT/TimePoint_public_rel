<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Script ist nur fuer die Kommandozeile gedacht.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$configuredSource = getenv('TIMEPOINT_SQLITE_SOURCE') ?: $projectRoot . '/assets/db/timetracking.sqlite';
$defaultSource = defaultSource($configuredSource);
$options = getopt('', ['source::', 'yes', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
TimePoint SQLite Migration

Nutzung:
  php scripts/migrate_sqlite_to_configured_db.php --source=/pfad/timetracking.sqlite --yes

Optionen:
  --source   Pfad zur bestehenden SQLite-Datei. Standard: assets/db/timetracking.sqlite
  --yes      Bestaetigt das Leeren der Zieltabellen vor dem Import.
  --dry-run  Zaehlt Datensaetze, schreibt aber nichts in die Ziel-Datenbank.
  --help     Zeigt diese Hilfe.

Wichtig:
  Fuehre zuerst das neue Web-Setup aus, damit config.local.php vorhanden ist.
  Das Script leert die TimePoint-Zieltabellen und importiert danach die SQLite-Daten.

TXT;
    exit(0);
}

$sourcePath = (string)($options['source'] ?? $defaultSource);
$dryRun = array_key_exists('dry-run', $options);
$confirmed = array_key_exists('yes', $options);

if (!is_file($sourcePath)) {
    fwrite(STDERR, "SQLite-Datei nicht gefunden: {$sourcePath}\n");
    exit(1);
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite ist nicht geladen. Bitte PHP mit SQLite-PDO-Treiber verwenden.\n");
    exit(1);
}

if (!$dryRun && !$confirmed) {
    fwrite(STDOUT, "Dieses Script leert die TimePoint-Zieltabellen und importiert danach {$sourcePath}.\n");
    fwrite(STDOUT, "Fortfahren? Tippe exakt YES: ");
    $answer = trim((string)fgets(STDIN));
    if ($answer !== 'YES') {
        fwrite(STDOUT, "Abgebrochen.\n");
        exit(0);
    }
}

require $projectRoot . '/config.php';

if (!isset($conn) || !$conn instanceof PDO) {
    fwrite(STDERR, "Ziel-Datenbank konnte nicht aus config.local.php geladen werden.\n");
    exit(1);
}

$target = $conn;
$targetDriver = TIMEPOINT_DB_DRIVER;
$source = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function defaultSource(string $configuredSource): string
{
    if (is_file($configuredSource)) {
        return $configuredSource;
    }

    $sourceDir = dirname($configuredSource);
    foreach (glob($sourceDir . '/*.{sqlite,sqlite3,db}', GLOB_BRACE) ?: [] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $configuredSource;
}

$tables = [
    'departments',
    'users',
    'zeiterfassung',
    'Feiertage',
    'ldap_settings',
    'pause_settings',
    'app_settings',
    'smtp_settings',
    'password_resets',
    'audit_log',
];

$identityTables = [
    'departments',
    'users',
    'zeiterfassung',
    'Feiertage',
    'ldap_settings',
    'pause_settings',
    'password_resets',
    'audit_log',
];

$tableAliases = [
    'Feiertage' => ['Feiertage', 'feiertage'],
];

function quoteIdentifier(string $identifier, string $driver): string
{
    if ($driver === 'mysql') {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    return '"' . str_replace('"', '""', $identifier) . '"';
}

function quoteTargetTable(string $table, string $driver): string
{
    return quoteIdentifier($driver === 'pgsql' ? strtolower($table) : $table, $driver);
}

function sourceTableName(PDO $source, string $table, array $aliases): ?string
{
    $candidates = $aliases[$table] ?? [$table];
    $stmt = $source->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND lower(name) = lower(?) LIMIT 1");

    foreach ($candidates as $candidate) {
        $stmt->execute([$candidate]);
        $name = $stmt->fetchColumn();
        if ($name !== false) {
            return (string)$name;
        }
    }

    return null;
}

function sourceColumns(PDO $source, string $table): array
{
    $columns = [];
    foreach ($source->query('PRAGMA table_info(' . quoteIdentifier($table, 'sqlite') . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[] = (string)$column['name'];
    }

    return $columns;
}

function targetColumns(PDO $target, string $driver, string $table): array
{
    if ($driver === 'pgsql') {
        $stmt = $target->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND lower(table_name) = lower(?)
            ORDER BY ordinal_position
        ");
    } else {
        $stmt = $target->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND lower(table_name) = lower(?)
            ORDER BY ordinal_position
        ");
    }

    $stmt->execute([$table]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function targetColumnTypes(PDO $target, string $driver, string $table): array
{
    if ($driver === 'pgsql') {
        $stmt = $target->prepare("
            SELECT column_name, COALESCE(udt_name, data_type) AS column_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND lower(table_name) = lower(?)
        ");
    } else {
        $stmt = $target->prepare("
            SELECT column_name, data_type AS column_type
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND lower(table_name) = lower(?)
        ");
    }

    $stmt->execute([$table]);

    $types = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $column = array_change_key_case($column, CASE_LOWER);
        if (!isset($column['column_name'])) {
            continue;
        }
        $types[(string)$column['column_name']] = strtolower((string)($column['column_type'] ?? ''));
    }

    return $types;
}

function normalizeValue(string $table, string $column, $value, string $columnType)
{
    if ($table === 'users' && $column === 'ueberstunden') {
        return 0.0;
    }

    if ($value !== '') {
        return $value;
    }

    $defaults = [
        'users' => [
            'regelarbeitszeit' => 8.0,
            'ueberstunden' => 0.0,
            'automatic_pause_deduction' => 1,
            'pause_duration' => 30,
            'vacation_days_per_year' => 30,
            'force_password_change' => 0,
        ],
        'smtp_settings' => [
            'enabled' => 0,
            'host' => 'smtp.office365.com',
            'port' => 587,
            'encryption' => 'starttls',
            'from_name' => 'TimePoint',
        ],
    ];

    if (array_key_exists($column, $defaults[$table] ?? [])) {
        return $defaults[$table][$column];
    }

    $textTypes = [
        'bpchar',
        'char',
        'character',
        'character varying',
        'json',
        'jsonb',
        'longtext',
        'mediumtext',
        'text',
        'tinytext',
        'varchar',
    ];

    return in_array($columnType, $textTypes, true) ? '' : null;
}

function clearTargetTables(PDO $target, string $driver, array $tables): void
{
    $autoIncrementTables = [
        'departments',
        'users',
        'zeiterfassung',
        'Feiertage',
        'ldap_settings',
        'pause_settings',
        'password_resets',
        'audit_log',
    ];

    if ($driver === 'pgsql') {
        $quotedTables = array_map(static fn ($table) => quoteTargetTable($table, $driver), $tables);
        $target->exec('TRUNCATE TABLE ' . implode(', ', $quotedTables) . ' RESTART IDENTITY CASCADE');
        return;
    }

    $target->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse($tables) as $table) {
        $target->exec('DELETE FROM ' . quoteTargetTable($table, $driver));
    }
    foreach ($autoIncrementTables as $table) {
        $target->exec('ALTER TABLE ' . quoteTargetTable($table, $driver) . ' AUTO_INCREMENT = 1');
    }
    $target->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function resetIdentity(PDO $target, string $driver, string $table): void
{
    if ($driver === 'pgsql') {
        $quotedTable = quoteTargetTable($table, $driver);
        $sequenceTable = strtolower($table);
        $sequence = $target->query("SELECT pg_get_serial_sequence('{$sequenceTable}', 'id')")->fetchColumn();
        if ($sequence !== false && $sequence !== null) {
            $target->exec("SELECT setval(" . $target->quote((string)$sequence) . ", COALESCE((SELECT MAX(id) FROM {$quotedTable}), 1), (SELECT COUNT(*) FROM {$quotedTable}) > 0)");
        }
        return;
    }

    $maxId = (int)$target->query('SELECT COALESCE(MAX(id), 0) FROM ' . quoteTargetTable($table, $driver))->fetchColumn();
    $target->exec('ALTER TABLE ' . quoteTargetTable($table, $driver) . ' AUTO_INCREMENT = ' . ($maxId + 1));
}

try {
    $summary = [];

    foreach ($tables as $table) {
        $sourceTable = sourceTableName($source, $table, $tableAliases);
        $summary[$table] = [
            'source_table' => $sourceTable,
            'rows' => $sourceTable ? (int)$source->query('SELECT COUNT(*) FROM ' . quoteIdentifier($sourceTable, 'sqlite'))->fetchColumn() : 0,
            'imported' => 0,
            'skipped' => $sourceTable === null,
        ];
    }

    echo "Quelle: {$sourcePath}\n";
    echo "Ziel: {$targetDriver}\n";
    foreach ($summary as $table => $info) {
        echo str_pad($table, 18) . ($info['skipped'] ? "nicht vorhanden\n" : $info['rows'] . " Datensaetze\n");
    }

    if ($dryRun) {
        echo "Dry-run abgeschlossen. Es wurde nichts geschrieben.\n";
        exit(0);
    }

    clearTargetTables($target, $targetDriver, $tables);
    $useTransaction = $targetDriver === 'pgsql';
    if ($useTransaction) {
        $target->beginTransaction();
    }

    foreach ($tables as $table) {
        $sourceTable = $summary[$table]['source_table'];
        if ($sourceTable === null) {
            continue;
        }

        $sourceColumns = sourceColumns($source, $sourceTable);
        $targetColumns = targetColumns($target, $targetDriver, $table);
        $targetColumnTypes = targetColumnTypes($target, $targetDriver, $table);
        $columns = array_values(array_intersect($sourceColumns, $targetColumns));

        if ($columns === []) {
            continue;
        }

        $quotedSourceColumns = array_map(static fn ($column) => quoteIdentifier($column, 'sqlite'), $columns);
        $quotedTargetColumns = array_map(static fn ($column) => quoteIdentifier($column, $targetDriver), $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $select = $source->query(
            'SELECT ' . implode(', ', $quotedSourceColumns)
            . ' FROM ' . quoteIdentifier($sourceTable, 'sqlite')
            . (in_array('id', $columns, true) ? ' ORDER BY id' : '')
        );
        $insert = $target->prepare(
            'INSERT INTO ' . quoteTargetTable($table, $targetDriver)
            . ' (' . implode(', ', $quotedTargetColumns) . ') VALUES (' . $placeholders . ')'
        );

        while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = normalizeValue($table, $column, $row[$column] ?? null, $targetColumnTypes[$column] ?? '');
            }
            $insert->execute($values);
            $summary[$table]['imported']++;
        }
    }

    foreach ($identityTables as $table) {
        resetIdentity($target, $targetDriver, $table);
    }

    if ($useTransaction) {
        $target->commit();
    }

    echo "\nMigration abgeschlossen:\n";
    foreach ($summary as $table => $info) {
        echo str_pad($table, 18) . $info['imported'] . " importiert\n";
    }
} catch (Throwable $e) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }

    fwrite(STDERR, "Migration fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}
