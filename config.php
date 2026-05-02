<?php

$database = __DIR__ . '/assets/db/timetracking.sqlite';
define('TIMEPOINT_VERSION', '1.0.2');
define('TIMEPOINT_CHANGELOG_URL', 'changelog.php?embed=1');
define('ENCRYPTION_KEY', 'your-encryption-key'); 
define('ENCRYPTION_METHOD', 'aes-256-cbc'); 


try {
    $conn = new PDO("sqlite:{$database}", null, null, [
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
        \PDO::ATTR_ERRMODE           => \PDO::ERRMODE_EXCEPTION
    ]);

    // SQL for creating 'zeiterfassung' table
    $createZeiterfassungSql = <<<SQL
CREATE TABLE IF NOT EXISTS zeiterfassung (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    startzeit   TEXT         NOT NULL,
    endzeit     TEXT,
    pause       INTEGER,
    beschreibung TEXT        DEFAULT '' NULL,
    standort    TEXT         DEFAULT '' NULL,
    user_id     INTEGER      NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
SQL;

    // SQL for creating 'Feiertage' table
    $createFeiertageSql = <<<SQL
    CREATE TABLE IF NOT EXISTS Feiertage (
        id  INTEGER  PRIMARY KEY AUTOINCREMENT,
        datum TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL
    );
SQL;

    // SQL for creating 'users' table
    $createUserSql = <<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        role TEXT NOT NULL DEFAULT 'user',
        token TEXT,
        department_id INTEGER,
        supervisor_id INTEGER,
        regelarbeitszeit REAL DEFAULT 8.0,
        ueberstunden REAL DEFAULT 0.0,
        automatic_pause_deduction INTEGER DEFAULT 1,
        pause_duration INTEGER DEFAULT 30,
        vacation_days_per_year INTEGER DEFAULT 30,
        force_password_change INTEGER DEFAULT 0,
        FOREIGN KEY (department_id) REFERENCES departments(id),
        FOREIGN KEY (supervisor_id) REFERENCES users(id)
    );
SQL;

    // SQL for creating 'departments' table
    $createDepartmentsSql = <<<SQL
    CREATE TABLE IF NOT EXISTS departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    );
SQL;

    // SQL for creating 'ldap_settings' table
    $createLdapSettingsSql = <<<SQL
    CREATE TABLE IF NOT EXISTS ldap_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ldap_host TEXT NOT NULL,
        ldap_port INTEGER NOT NULL,
        ldap_user TEXT NOT NULL,
        ldap_pass TEXT NOT NULL,
        ldap_base_dn TEXT NOT NULL
    );
SQL;

    // SQL for creating 'pause_settings' table
    $createPauseSettingsSql = <<<SQL
    CREATE TABLE IF NOT EXISTS pause_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hours_threshold INTEGER NOT NULL,
        minimum_pause INTEGER NOT NULL
    );
SQL;

    $createAppSettingsSql = <<<SQL
    CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL
    );
SQL;

    $createSmtpSettingsSql = <<<SQL
    CREATE TABLE IF NOT EXISTS smtp_settings (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        enabled INTEGER NOT NULL DEFAULT 0,
        host TEXT NOT NULL DEFAULT 'smtp.office365.com',
        port INTEGER NOT NULL DEFAULT 587,
        encryption TEXT NOT NULL DEFAULT 'starttls',
        username TEXT DEFAULT '',
        password TEXT DEFAULT '',
        from_email TEXT DEFAULT '',
        from_name TEXT DEFAULT 'TimePoint',
        updated_at TEXT
    );
SQL;

    $createPasswordResetsSql = <<<SQL
    CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
SQL;

    $createAuditLogSql = <<<SQL
    CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actor_user_id INTEGER,
        actor_username TEXT,
        actor_role TEXT,
        action TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id INTEGER,
        target_user_id INTEGER,
        old_values TEXT,
        new_values TEXT,
        reason TEXT,
        ip_address TEXT,
        user_agent TEXT,
        previous_hash TEXT NOT NULL,
        entry_hash TEXT NOT NULL UNIQUE,
        hmac_signature TEXT NOT NULL
    );
SQL;

    // Execute the SQL statements
    $conn->exec($createUserSql);
    $conn->exec($createZeiterfassungSql);
    $conn->exec($createFeiertageSql);
    $conn->exec($createDepartmentsSql);
    $conn->exec($createLdapSettingsSql);
    $conn->exec($createPauseSettingsSql);
    $conn->exec($createAppSettingsSql);
    $conn->exec($createSmtpSettingsSql);
    $conn->exec($createPasswordResetsSql);
    $conn->exec($createAuditLogSql);

    $auditColumnInfo = $conn->query("PRAGMA table_info(audit_log)")->fetchAll();
    $auditColumns = array_map(static function ($column) {
        return is_array($column) ? $column['name'] : $column->name;
    }, $auditColumnInfo);
    $auditColumnDefinitions = [
        'created_at' => "TEXT NOT NULL DEFAULT ''",
        'actor_user_id' => "INTEGER",
        'actor_username' => "TEXT",
        'actor_role' => "TEXT",
        'action' => "TEXT NOT NULL DEFAULT ''",
        'entity_type' => "TEXT NOT NULL DEFAULT ''",
        'entity_id' => "INTEGER",
        'target_user_id' => "INTEGER",
        'old_values' => "TEXT",
        'new_values' => "TEXT",
        'reason' => "TEXT",
        'ip_address' => "TEXT",
        'user_agent' => "TEXT",
        'previous_hash' => "TEXT NOT NULL DEFAULT ''",
        'entry_hash' => "TEXT NOT NULL DEFAULT ''",
        'hmac_signature' => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($auditColumnDefinitions as $auditColumn => $auditDefinition) {
        if (!in_array($auditColumn, $auditColumns, true)) {
            $conn->exec("ALTER TABLE audit_log ADD COLUMN {$auditColumn} {$auditDefinition}");
        }
    }

    $conn->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log(entity_type, entity_id)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_target_user ON audit_log(target_user_id)");
    $conn->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
    $conn->exec("CREATE TRIGGER IF NOT EXISTS audit_log_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");

    $defaultSettings = [
        'show_privacy_link' => '1',
        'show_imprint_link' => '1',
    ];
    $stmt = $conn->prepare("INSERT OR IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaultSettings as $settingKey => $settingValue) {
        $stmt->execute([$settingKey, $settingValue]);
    }

    $conn->exec("INSERT OR IGNORE INTO smtp_settings (id, enabled, host, port, encryption, from_name) VALUES (1, 0, 'smtp.office365.com', 587, 'starttls', 'TimePoint')");

    // Check if pause_settings table is empty and insert default values if needed
    $pauseSettingsCount = $conn->query("SELECT COUNT(*) as count FROM pause_settings")->fetch()->count;
    if ($pauseSettingsCount == 0) {
        $conn->exec("INSERT INTO pause_settings (hours_threshold, minimum_pause) VALUES (6, 30), (9, 45)");
    }

    $result = $conn->query("PRAGMA table_info(users)")->fetchAll();
    $columns = array_map(static function ($column) {
        return is_array($column) ? $column['name'] : $column->name;
    }, $result);

    if (!in_array('automatic_pause_deduction', $columns, true)) {
        $conn->exec("ALTER TABLE users ADD COLUMN automatic_pause_deduction INTEGER DEFAULT 1");
    }

    if (!in_array('pause_duration', $columns, true)) {
        $conn->exec("ALTER TABLE users ADD COLUMN pause_duration INTEGER DEFAULT 30");
    }

    if (!in_array('vacation_days_per_year', $columns, true)) {
        $conn->exec("ALTER TABLE users ADD COLUMN vacation_days_per_year INTEGER DEFAULT 30");
    }

    if (!in_array('force_password_change', $columns, true)) {
        $conn->exec("ALTER TABLE users ADD COLUMN force_password_change INTEGER DEFAULT 0");
    }
} catch (\PDOException $e) {
    exit('Could not connect to the SQLite database: ' . $e->getMessage());
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

        $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
        $stmt->execute([$key, $value]);
    }
}
