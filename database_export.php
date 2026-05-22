<?php
session_start();
include __DIR__ . '/config.php';

$lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'de');
$lang = in_array($lang, ['de', 'en'], true) ? $lang : 'de';
$_SESSION['lang'] = $lang;
require_once __DIR__ . "/languages/$lang.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit(ERROR_NO_PERMISSION);
}

function tpDatabaseExportFail(string $message): void
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function tpDatabaseExportFindExecutable(array $candidates): ?string
{
    if (!function_exists('shell_exec')) {
        return $candidates[0] ?? null;
    }

    foreach ($candidates as $candidate) {
        $path = trim((string)@shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if ($path !== '') {
            return $path;
        }
    }

    return null;
}

if (!function_exists('proc_open')) {
    tpDatabaseExportFail(DATABASE_EXPORT_PROC_OPEN_DISABLED);
}

$configPath = getenv('TIMEPOINT_CONFIG_PATH') ?: __DIR__ . '/config.local.php';
$exportConfig = require $configPath;
$driver = $exportConfig['driver'] ?? TIMEPOINT_DB_DRIVER;
$host = (string)($exportConfig['host'] ?? 'localhost');
$port = (string)($exportConfig['port'] ?? ($driver === 'mysql' ? 3306 : 5432));
$database = (string)($exportConfig['database'] ?? 'timepoint');
$username = (string)($exportConfig['username'] ?? '');
$password = (string)($exportConfig['password'] ?? '');

if ($driver === 'pgsql') {
    $binary = tpDatabaseExportFindExecutable(['pg_dump']);
    if ($binary === null) {
        tpDatabaseExportFail(sprintf(DATABASE_EXPORT_TOOL_MISSING, 'pg_dump'));
    }

    $command = [
        $binary,
        '--host=' . $host,
        '--port=' . $port,
        '--username=' . $username,
        '--dbname=' . $database,
        '--format=plain',
        '--no-owner',
        '--no-privileges',
    ];
    putenv('PGPASSWORD=' . $password);
} elseif ($driver === 'mysql') {
    $binary = tpDatabaseExportFindExecutable(['mariadb-dump', 'mysqldump']);
    if ($binary === null) {
        tpDatabaseExportFail(sprintf(DATABASE_EXPORT_TOOL_MISSING, 'mariadb-dump / mysqldump'));
    }

    $command = [
        $binary,
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $username,
        '--single-transaction',
        '--quick',
        '--default-character-set=utf8mb4',
        $database,
    ];
    putenv('MYSQL_PWD=' . $password);
} else {
    tpDatabaseExportFail(sprintf(DATABASE_EXPORT_UNSUPPORTED_DRIVER, $driver));
}

$tmpFile = tempnam(sys_get_temp_dir(), 'timepoint-db-');
if ($tmpFile === false) {
    tpDatabaseExportFail(DATABASE_EXPORT_TEMP_FAILED);
}

$descriptors = [
    1 => ['file', $tmpFile, 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptors, $pipes, __DIR__);
if (!is_resource($process)) {
    @unlink($tmpFile);
    tpDatabaseExportFail(DATABASE_EXPORT_START_FAILED);
}

$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);

putenv('PGPASSWORD');
putenv('MYSQL_PWD');

if ($exitCode !== 0) {
    @unlink($tmpFile);
    $details = trim((string)$stderr);
    tpDatabaseExportFail(sprintf(DATABASE_EXPORT_FAILED, $details !== '' ? $details : 'Exit code ' . $exitCode));
}

$safeDatabase = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $database) ?: 'timepoint';
$exportDriver = $driver === 'pgsql' ? 'postgresql' : 'mariadb';
$filename = sprintf('timepoint_%s_%s_%s.sql', $exportDriver, $safeDatabase, date('Y-m-d_H-i-s'));

header('Content-Type: application/sql; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('X-Content-Type-Options: nosniff');

readfile($tmpFile);
@unlink($tmpFile);
exit;
