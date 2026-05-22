<?php

define('TIMEPOINT_VERSION', '1.0.3');
define('TIMEPOINT_CHANGELOG_URL', 'changelog.php?embed=1');
define('ENCRYPTION_KEY', 'your-encryption-key');
define('ENCRYPTION_METHOD', 'aes-256-cbc');

$setupConfigPath = getenv('TIMEPOINT_CONFIG_PATH') ?: __DIR__ . '/config.local.php';
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

function tpEnv(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string)$_SERVER[$key];
    }

    return $default;
}

function tpIsDemoMode(): bool
{
    return in_array(strtolower(tpEnv('TIMEPOINT_DEMO_MODE')), ['1', 'true', 'yes', 'on'], true);
}

function tpDemoAdminUsername(): string
{
    return tpEnv('TIMEPOINT_DEMO_ADMIN_USERNAME', 'admin');
}

function tpDemoAdminEmail(): string
{
    return tpEnv('TIMEPOINT_DEMO_ADMIN_EMAIL', 'demo-admin@example.com');
}

function tpDemoAdminPassword(): string
{
    return tpEnv('TIMEPOINT_DEMO_ADMIN_PASSWORD', 'timepoint-demo-admin');
}

function tpWriteDemoConfigIfPossible(string $configPath): void
{
    if (!tpIsDemoMode() || file_exists($configPath)) {
        return;
    }

    $values = [
        'driver' => tpEnv('TIMEPOINT_DB_DRIVER', 'pgsql'),
        'host' => tpEnv('TIMEPOINT_DB_HOST'),
        'port' => (int)tpEnv('TIMEPOINT_DB_PORT', tpEnv('TIMEPOINT_DB_DRIVER') === 'mysql' ? '3306' : '5432'),
        'database' => tpEnv('TIMEPOINT_DB_NAME'),
        'username' => tpEnv('TIMEPOINT_DB_USER'),
        'password' => tpEnv('TIMEPOINT_DB_PASSWORD'),
    ];

    if ($values['host'] === '' || $values['database'] === '' || $values['username'] === '') {
        return;
    }

    $configDir = dirname($configPath);
    if (!is_dir($configDir)) {
        mkdir($configDir, 0750, true);
    }

    $config = "<?php\n\nreturn " . var_export($values, true) . ";\n";
    file_put_contents($configPath, $config, LOCK_EX);
}

tpWriteDemoConfigIfPossible($setupConfigPath);

if (!file_exists($setupConfigPath)) {
    if ($currentScript !== 'setup.php') {
        header('Location: setup.php');
        exit();
    }

    return;
}

$dbConfig = require $setupConfigPath;
$dbDriver = $dbConfig['driver'] ?? 'pgsql';
define('TIMEPOINT_DB_DRIVER', $dbDriver);

try {
    $conn = new PDO(tpBuildDsn($dbConfig), $dbConfig['username'] ?? '', $dbConfig['password'] ?? '', [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    initializeDatabase($conn, $dbDriver);
    tpEnsureDemoAdmin($conn);
} catch (PDOException $e) {
    exit('Could not connect to the TimePoint database: ' . $e->getMessage());
}

function tpBuildDsn(array $config): string
{
    $driver = $config['driver'] ?? 'pgsql';
    $host = $config['host'] ?? 'localhost';
    $port = (int)($config['port'] ?? ($driver === 'mysql' ? 3306 : 5432));
    $database = $config['database'] ?? 'timepoint';
    $charset = $driver === 'mysql' ? ';charset=utf8mb4' : '';

    return "{$driver}:host={$host};port={$port};dbname={$database}{$charset}";
}

function tpSqlDate(string $column): string
{
    return TIMEPOINT_DB_DRIVER === 'pgsql' ? "CAST({$column} AS date)" : "DATE({$column})";
}

function tpSqlWeek(string $column): string
{
    if (TIMEPOINT_DB_DRIVER === 'pgsql') {
        return "EXTRACT(WEEK FROM {$column})";
    }

    return "WEEK({$column}, 3)";
}

function tpSqlYear(string $column): string
{
    return TIMEPOINT_DB_DRIVER === 'pgsql' ? "EXTRACT(YEAR FROM {$column})" : "YEAR({$column})";
}

function tpSqlMonth(string $column): string
{
    return TIMEPOINT_DB_DRIVER === 'pgsql' ? "EXTRACT(MONTH FROM {$column})" : "MONTH({$column})";
}

function tpSqlDurationMinutes(string $startColumn, string $endColumn): string
{
    if (TIMEPOINT_DB_DRIVER === 'pgsql') {
        return "(EXTRACT(EPOCH FROM ({$endColumn}::timestamp - {$startColumn}::timestamp)) / 60)";
    }

    return "TIMESTAMPDIFF(MINUTE, {$startColumn}, {$endColumn})";
}

function tpSqlNow(): string
{
    return TIMEPOINT_DB_DRIVER === 'pgsql' ? 'CURRENT_TIMESTAMP' : 'NOW()';
}

function tpColumnExists(PDO $conn, string $table, string $column): bool
{
    if (TIMEPOINT_DB_DRIVER === 'pgsql') {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?");
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    }

    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function tpIndexExists(PDO $conn, string $table, string $index): bool
{
    if (TIMEPOINT_DB_DRIVER === 'pgsql') {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?");
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
    }

    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function tpCreateIndexIfMissing(PDO $conn, string $table, string $index, string $columns): void
{
    if (tpIndexExists($conn, $table, $index)) {
        return;
    }

    $quotedTable = TIMEPOINT_DB_DRIVER === 'pgsql' ? '"' . strtolower($table) . '"' : '`' . str_replace('`', '``', $table) . '`';
    $conn->exec("CREATE INDEX {$index} ON {$quotedTable}({$columns})");
}

function tpNormalizeMysqlColumnTypes(PDO $conn): void
{
    if (TIMEPOINT_DB_DRIVER !== 'mysql') {
        return;
    }

    $conn->exec("
        ALTER TABLE smtp_settings
        MODIFY from_name VARCHAR(255) DEFAULT 'TimePoint'
    ");

    $conn->exec("
        ALTER TABLE audit_log
        MODIFY actor_username VARCHAR(255) NULL,
        MODIFY actor_role VARCHAR(50) NULL,
        MODIFY action VARCHAR(120) NOT NULL,
        MODIFY entity_type VARCHAR(120) NOT NULL,
        MODIFY previous_hash VARCHAR(255) NOT NULL,
        MODIFY entry_hash VARCHAR(255) NOT NULL,
        MODIFY hmac_signature VARCHAR(255) NOT NULL
    ");
}

function tpInsertIgnore(PDO $conn, string $sql, array $params = []): void
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000' && $e->getCode() !== '23505') {
            throw $e;
        }
    }
}

function initializeDatabase(PDO $conn, string $driver): void
{
    $id = $driver === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $smallBool = $driver === 'pgsql' ? 'SMALLINT' : 'TINYINT';
    $text = $driver === 'pgsql' ? 'TEXT' : 'TEXT';

    $conn->exec("
        CREATE TABLE IF NOT EXISTS departments (
            id {$id},
            name VARCHAR(255) NOT NULL UNIQUE
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS users (
            id {$id},
            username VARCHAR(255) NOT NULL UNIQUE,
            password {$text} NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            role VARCHAR(50) NOT NULL DEFAULT 'user',
            token {$text},
            department_id INT,
            supervisor_id INT,
            regelarbeitszeit DOUBLE PRECISION DEFAULT 8.0,
            ueberstunden DOUBLE PRECISION DEFAULT 0.0,
            automatic_pause_deduction {$smallBool} DEFAULT 1,
            pause_duration INT DEFAULT 30,
            vacation_days_per_year INT DEFAULT 30,
            force_password_change {$smallBool} DEFAULT 0
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS zeiterfassung (
            id {$id},
            startzeit TIMESTAMP NOT NULL,
            endzeit TIMESTAMP NULL,
            pause INT,
            beschreibung {$text} DEFAULT NULL,
            standort {$text} DEFAULT NULL,
            user_id INT NOT NULL
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS Feiertage (
            id {$id},
            datum DATE UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ldap_settings (
            id {$id},
            ldap_host VARCHAR(255) NOT NULL,
            ldap_port INT NOT NULL,
            ldap_user {$text} NOT NULL,
            ldap_pass {$text} NOT NULL,
            ldap_base_dn {$text} NOT NULL
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS pause_settings (
            id {$id},
            hours_threshold INT NOT NULL,
            minimum_pause INT NOT NULL
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value {$text} NOT NULL
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS smtp_settings (
            id INT PRIMARY KEY,
            enabled {$smallBool} NOT NULL DEFAULT 0,
            host VARCHAR(255) NOT NULL DEFAULT 'smtp.office365.com',
            port INT NOT NULL DEFAULT 587,
            encryption VARCHAR(30) NOT NULL DEFAULT 'starttls',
            username {$text} DEFAULT NULL,
            password {$text} DEFAULT NULL,
            from_email {$text} DEFAULT NULL,
            from_name VARCHAR(255) DEFAULT 'TimePoint',
            updated_at TIMESTAMP NULL
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id {$id},
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id {$id},
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actor_user_id INT,
            actor_username VARCHAR(255),
            actor_role VARCHAR(50),
            action VARCHAR(120) NOT NULL,
            entity_type VARCHAR(120) NOT NULL,
            entity_id INT,
            target_user_id INT,
            old_values {$text},
            new_values {$text},
            reason {$text},
            ip_address {$text},
            user_agent {$text},
            previous_hash VARCHAR(255) NOT NULL,
            entry_hash VARCHAR(255) NOT NULL UNIQUE,
            hmac_signature VARCHAR(255) NOT NULL
        )
    ");

    $userColumns = [
        'automatic_pause_deduction' => "{$smallBool} DEFAULT 1",
        'pause_duration' => 'INT DEFAULT 30',
        'vacation_days_per_year' => 'INT DEFAULT 30',
        'force_password_change' => "{$smallBool} DEFAULT 0",
    ];

    foreach ($userColumns as $column => $definition) {
        if (!tpColumnExists($conn, 'users', $column)) {
            $conn->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }

    tpNormalizeMysqlColumnTypes($conn);

    tpCreateIndexIfMissing($conn, 'audit_log', 'idx_audit_log_created_at', 'created_at');
    tpCreateIndexIfMissing($conn, 'audit_log', 'idx_audit_log_entity', 'entity_type, entity_id');
    tpCreateIndexIfMissing($conn, 'audit_log', 'idx_audit_log_target_user', 'target_user_id');

    tpInsertIgnore($conn, "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)", ['show_privacy_link', '1']);
    tpInsertIgnore($conn, "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)", ['show_imprint_link', '1']);
    tpInsertIgnore($conn, "INSERT INTO smtp_settings (id, enabled, host, port, encryption, from_name) VALUES (1, 0, 'smtp.office365.com', 587, 'starttls', 'TimePoint')");

    $pauseSettingsCount = (int)$conn->query("SELECT COUNT(*) FROM pause_settings")->fetchColumn();
    if ($pauseSettingsCount === 0) {
        $conn->exec("INSERT INTO pause_settings (hours_threshold, minimum_pause) VALUES (6, 30), (9, 45)");
    }
}

function tpEnsureDemoAdmin(PDO $conn): void
{
    if (!tpIsDemoMode()) {
        return;
    }

    $username = tpDemoAdminUsername();
    $email = tpDemoAdminEmail();
    $password = tpDemoAdminPassword();

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ? ORDER BY CASE WHEN username = ? THEN 0 ELSE 1 END, id LIMIT 1");
    $stmt->execute([$username, $email, $username]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);

    if ($user) {
        $passwordHash = (isset($user->password) && password_verify($password, (string)$user->password))
            ? (string)$user->password
            : password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE users
            SET username = ?, email = ?, password = ?, role = 'admin', force_password_change = 0
            WHERE id = ?
        ");
        $stmt->execute([$username, $email, $passwordHash, (int)$user->id]);
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO users (username, password, email, role, force_password_change)
        VALUES (?, ?, ?, 'admin', 0)
    ");
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
}

function tpIsDemoAdminUserId($userId): bool
{
    global $conn;

    if (!tpIsDemoMode() || empty($userId)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND username = ? AND email = ? AND role = 'admin'");
    $stmt->execute([(int)$userId, tpDemoAdminUsername(), tpDemoAdminEmail()]);

    return (int)$stmt->fetchColumn() > 0;
}

function tpDemoModeMessage(): string
{
    return 'Der Demo-Admin ist im Demo-Modus geschuetzt und kann nicht veraendert werden.';
}

if (!function_exists('getAppSetting')) {
    function getAppSetting(string $key, string $default = ''): string
    {
        global $conn;

        $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string)$value;
    }
}

if (!function_exists('setAppSetting')) {
    function setAppSetting(string $key, string $value): void
    {
        global $conn;

        if (TIMEPOINT_DB_DRIVER === 'pgsql') {
            $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
        } else {
            $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        }

        $stmt->execute([$key, $value]);
    }
}

if (!function_exists('tpLegalPageSettingKey')) {
    function tpLegalPageSettingKey(string $page): string
    {
        $keys = [
            'imprint' => 'legal_imprint_content',
            'privacy' => 'legal_privacy_content',
        ];

        if (!isset($keys[$page])) {
            throw new InvalidArgumentException('Unbekannte rechtliche Seite.');
        }

        return $keys[$page];
    }
}

if (!function_exists('tpLegalPageDefaultContent')) {
    function tpLegalPageDefaultContent(string $page): string
    {
        $files = [
            'imprint' => __DIR__ . '/partials/imprint_content.php',
            'privacy' => __DIR__ . '/partials/privacy_content.php',
        ];

        if (!isset($files[$page]) || !is_file($files[$page])) {
            return '';
        }

        ob_start();
        include $files[$page];
        return trim((string)ob_get_clean());
    }
}

if (!function_exists('tpSanitizeLegalPageContent')) {
    function tpSanitizeLegalPageContent(string $html): string
    {
        $html = preg_replace('#<\s*(script|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*(script|iframe|object|embed)\b[^>]*?/?>#is', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '$1="#"', $html) ?? $html;

        return trim($html);
    }
}

if (!function_exists('getLegalPageContent')) {
    function getLegalPageContent(string $page): string
    {
        $content = getAppSetting(tpLegalPageSettingKey($page), '');

        return trim($content) !== '' ? $content : tpLegalPageDefaultContent($page);
    }
}

if (!function_exists('setLegalPageContent')) {
    function setLegalPageContent(string $page, string $content): void
    {
        setAppSetting(tpLegalPageSettingKey($page), tpSanitizeLegalPageContent($content));
    }
}

if (!function_exists('renderLegalPageContent')) {
    function renderLegalPageContent(string $page): void
    {
        echo getLegalPageContent($page);
    }
}
