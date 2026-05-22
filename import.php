<?php
session_start();
include 'config.php';

$lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'de');
$lang = in_array($lang, ['de', 'en'], true) ? $lang : 'de';
$_SESSION['lang'] = $lang;
require_once __DIR__ . "/languages/$lang.php";

$stmt = $conn->query("SELECT COUNT(*) FROM users");
$targetUserCount = (int)$stmt->fetchColumn();
$isAdmin = isset($_SESSION['user_id'], $_SESSION['role']) && $_SESSION['role'] === 'admin';

if ($targetUserCount > 0 && !$isAdmin) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$runtimeDir = getenv('TIMEPOINT_MIGRATION_UPLOAD_DIR') ?: __DIR__ . '/runtime';
$configuredSource = getenv('TIMEPOINT_SQLITE_SOURCE') ?: __DIR__ . '/assets/db/timetracking.sqlite';
$defaultSource = tpImportDefaultSource($configuredSource);
$message = '';
$error = '';
$summary = [];
$sourcePath = $defaultSource;
$themeMode = $_SESSION['theme_mode'] ?? 'system';
$resolvedTheme = $themeMode === 'dark' ? 'dark' : 'light';

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

function tpImportDefaultSource(string $configuredSource): string
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

function tpImportQuoteIdentifier(string $identifier, string $driver): string
{
    if ($driver === 'mysql') {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    return '"' . str_replace('"', '""', $identifier) . '"';
}

function tpImportQuoteTargetTable(string $table, string $driver): string
{
    return tpImportQuoteIdentifier($driver === 'pgsql' ? strtolower($table) : $table, $driver);
}

function tpImportSourceTableName(PDO $source, string $table, array $aliases): ?string
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

function tpImportSourceColumns(PDO $source, string $table): array
{
    $columns = [];
    $quotedTable = tpImportQuoteIdentifier($table, 'sqlite');
    foreach ($source->query("PRAGMA table_info({$quotedTable})")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[] = (string)$column['name'];
    }

    return $columns;
}

function tpImportTargetColumns(PDO $target, string $driver, string $table): array
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

function tpImportTargetColumnTypes(PDO $target, string $driver, string $table): array
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

function tpImportNormalizeValue(string $table, string $column, $value, string $columnType)
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

function tpImportOpenSource(string $sourcePath): PDO
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('pdo_sqlite ist nicht geladen. Bitte PHP mit SQLite-PDO-Treiber verwenden.');
    }

    if (!is_file($sourcePath)) {
        throw new RuntimeException('SQLite-Datei nicht gefunden: ' . $sourcePath);
    }

    return new PDO('sqlite:' . $sourcePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function tpImportBuildSummary(PDO $source, array $tables, array $tableAliases): array
{
    $summary = [];
    foreach ($tables as $table) {
        $sourceTable = tpImportSourceTableName($source, $table, $tableAliases);
        $summary[$table] = [
            'source_table' => $sourceTable,
            'rows' => $sourceTable ? (int)$source->query('SELECT COUNT(*) FROM ' . tpImportQuoteIdentifier($sourceTable, 'sqlite'))->fetchColumn() : 0,
            'imported' => 0,
            'skipped' => $sourceTable === null,
        ];
    }

    return $summary;
}

function tpImportClearTargetTables(PDO $target, string $driver, array $tables, array $identityTables): void
{
    if ($driver === 'pgsql') {
        $quotedTables = array_map(static fn ($table) => tpImportQuoteTargetTable($table, $driver), $tables);
        $target->exec('TRUNCATE TABLE ' . implode(', ', $quotedTables) . ' RESTART IDENTITY CASCADE');
        return;
    }

    $target->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse($tables) as $table) {
        $target->exec('DELETE FROM ' . tpImportQuoteTargetTable($table, $driver));
    }
    foreach ($identityTables as $table) {
        $target->exec('ALTER TABLE ' . tpImportQuoteTargetTable($table, $driver) . ' AUTO_INCREMENT = 1');
    }
    $target->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function tpImportResetIdentity(PDO $target, string $driver, string $table): void
{
    if ($driver === 'pgsql') {
        $quotedTable = tpImportQuoteTargetTable($table, $driver);
        $sequenceTable = strtolower($table);
        $sequence = $target->query("SELECT pg_get_serial_sequence('{$sequenceTable}', 'id')")->fetchColumn();
        if ($sequence !== false && $sequence !== null) {
            $target->exec("SELECT setval(" . $target->quote((string)$sequence) . ", COALESCE((SELECT MAX(id) FROM {$quotedTable}), 1), (SELECT COUNT(*) FROM {$quotedTable}) > 0)");
        }
        return;
    }

    $maxId = (int)$target->query('SELECT COALESCE(MAX(id), 0) FROM ' . tpImportQuoteTargetTable($table, $driver))->fetchColumn();
    $target->exec('ALTER TABLE ' . tpImportQuoteTargetTable($table, $driver) . ' AUTO_INCREMENT = ' . ($maxId + 1));
}

function tpImportRun(PDO $source, PDO $target, string $targetDriver, array $tables, array $identityTables, array $tableAliases): array
{
    $summary = tpImportBuildSummary($source, $tables, $tableAliases);

    tpImportClearTargetTables($target, $targetDriver, $tables, $identityTables);
    $useTransaction = $targetDriver === 'pgsql';
    if ($useTransaction) {
        $target->beginTransaction();
    }

    foreach ($tables as $table) {
        $sourceTable = $summary[$table]['source_table'];
        if ($sourceTable === null) {
            continue;
        }

        $sourceColumns = tpImportSourceColumns($source, $sourceTable);
        $targetColumns = tpImportTargetColumns($target, $targetDriver, $table);
        $targetColumnTypes = tpImportTargetColumnTypes($target, $targetDriver, $table);
        $columns = array_values(array_intersect($sourceColumns, $targetColumns));

        if ($columns === []) {
            continue;
        }

        $quotedSourceColumns = array_map(static fn ($column) => tpImportQuoteIdentifier($column, 'sqlite'), $columns);
        $quotedTargetColumns = array_map(static fn ($column) => tpImportQuoteIdentifier($column, $targetDriver), $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $select = $source->query(
            'SELECT ' . implode(', ', $quotedSourceColumns)
            . ' FROM ' . tpImportQuoteIdentifier($sourceTable, 'sqlite')
            . (in_array('id', $columns, true) ? ' ORDER BY id' : '')
        );

        $insert = $target->prepare(
            'INSERT INTO ' . tpImportQuoteTargetTable($table, $targetDriver)
            . ' (' . implode(', ', $quotedTargetColumns) . ') VALUES (' . $placeholders . ')'
        );

        while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = tpImportNormalizeValue($table, $column, $row[$column] ?? null, $targetColumnTypes[$column] ?? '');
            }
            $insert->execute($values);
            $summary[$table]['imported']++;
        }
    }

    foreach ($identityTables as $table) {
        tpImportResetIdentity($target, $targetDriver, $table);
    }

    if ($useTransaction) {
        $target->commit();
    }
    return $summary;
}

function tpImportResolveSource(string $runtimeDir, string $defaultSource): string
{
    if (!empty($_FILES['sqlite_file']['tmp_name']) && is_uploaded_file($_FILES['sqlite_file']['tmp_name'])) {
        if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0750, true)) {
            throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
        }

        $targetPath = rtrim($runtimeDir, '/\\') . '/uploaded_sqlite_migration.sqlite';
        if (!move_uploaded_file($_FILES['sqlite_file']['tmp_name'], $targetPath)) {
            throw new RuntimeException('Upload konnte nicht gespeichert werden.');
        }

        return $targetPath;
    }

    $sourcePath = trim((string)($_POST['source_path'] ?? ''));
    if ($sourcePath !== '') {
        return $sourcePath;
    }

    return $defaultSource;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new RuntimeException('Ungueltiges CSRF-Token.');
        }

        $sourcePath = tpImportResolveSource($runtimeDir, $defaultSource);
        $source = tpImportOpenSource($sourcePath);

        if (isset($_POST['dry_run'])) {
            $summary = tpImportBuildSummary($source, $tables, $tableAliases);
            $message = 'Trockenlauf abgeschlossen. Es wurde nichts importiert.';
        } elseif (isset($_POST['run_import'])) {
            if (tpIsDemoMode()) {
                throw new RuntimeException('Der Datenimport ist im Demo-Modus deaktiviert.');
            }

            if (empty($_POST['confirm_import'])) {
                throw new RuntimeException('Bitte bestaetigen, dass die Zieltabellen geleert werden duerfen.');
            }

            $summary = tpImportRun($source, $conn, TIMEPOINT_DB_DRIVER, $tables, $identityTables, $tableAliases);
            $message = 'Migration erfolgreich abgeschlossen.';
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" data-theme="<?= htmlspecialchars($resolvedTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQLite Migration - TimePoint</title>
    <link rel="icon" href="assets/timepoint_icon.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.9.4/dist/full.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="bg-base-200 text-base-content min-h-screen">
    <main class="container mx-auto px-4 py-8">
        <div class="mb-8 text-center">
            <img src="assets/timepoint_icon.png" alt="TimePoint Logo" class="w-16 h-16 mx-auto mb-3">
            <h1 class="text-4xl font-bold">SQLite Migration</h1>
            <p class="mt-2 opacity-70">Bestehende TimePoint-Daten in <?= TIMEPOINT_DB_DRIVER === 'pgsql' ? 'PostgreSQL' : 'MariaDB' ?> uebernehmen.</p>
        </div>

        <?php if ($message) : ?>
            <div class="alert alert-success shadow-lg max-w-5xl mx-auto mb-6">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error) : ?>
            <div class="alert alert-error shadow-lg max-w-5xl mx-auto mb-6">
                <i class="fas fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 max-w-5xl mx-auto">
            <section class="card bg-base-100 shadow-xl lg:col-span-2">
                <div class="card-body">
                    <h2 class="card-title text-2xl">
                        <i class="fas fa-file-import mr-2"></i>Quelle auswaehlen
                    </h2>
                    <form method="post" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="form-control">
                            <label class="label" for="sqlite_file">
                                <span class="label-text">SQLite-Datei hochladen</span>
                            </label>
                            <input type="file" id="sqlite_file" name="sqlite_file" class="file-input file-input-bordered w-full" accept=".sqlite,.db">
                        </div>
                        <div class="divider">oder</div>
                        <div class="form-control">
                            <label class="label" for="source_path">
                                <span class="label-text">Serverpfad zur SQLite-Datei</span>
                            </label>
                            <input type="text" id="source_path" name="source_path" class="input input-bordered" value="<?= htmlspecialchars($sourcePath) ?>">
                        </div>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="confirm_import" value="1" class="checkbox checkbox-error">
                            <span class="label-text">Ich bestaetige, dass die Zieltabellen vor dem Import geleert werden.</span>
                        </label>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <button type="submit" name="dry_run" value="1" class="btn btn-secondary">
                                <i class="fas fa-magnifying-glass-chart mr-2"></i><?= IMPORT_DRY_RUN ?>
                            </button>
                            <button type="submit" name="run_import" value="1" class="btn btn-primary">
                                <i class="fas fa-database mr-2"></i><?= IMPORT_START_MIGRATION ?>
                            </button>
                            <a href="admin.php#database-operations" class="btn btn-ghost"><?= COMMON_BACK ?></a>
                        </div>
                    </form>
                </div>
            </section>

            <aside class="stat bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-box shadow-lg text-white">
                <div class="stat-figure opacity-70">
                    <i class="fas fa-server fa-3x"></i>
                </div>
                <div class="stat-title text-lg font-semibold opacity-80 text-white"><?= COMMON_TARGET_DATABASE ?></div>
                <div class="stat-value text-3xl"><?= TIMEPOINT_DB_DRIVER === 'pgsql' ? 'PostgreSQL' : 'MariaDB' ?></div>
            </aside>
        </div>

        <?php if ($summary) : ?>
            <section class="card bg-base-100 shadow-xl max-w-5xl mx-auto mt-6">
                <div class="card-body">
                    <h2 class="card-title text-2xl">
                        <i class="fas fa-table mr-2"></i>Ergebnis
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Tabelle</th>
                                    <th>Quelle</th>
                                    <th>Gefunden</th>
                                    <th>Importiert</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary as $table => $info) : ?>
                                    <tr>
                                        <td><?= htmlspecialchars($table) ?></td>
                                        <td><?= htmlspecialchars($info['source_table'] ?? '-') ?></td>
                                        <td><?= (int)$info['rows'] ?></td>
                                        <td><?= (int)$info['imported'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
