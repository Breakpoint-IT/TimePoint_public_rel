<?php

require_once __DIR__ . '/../security/crypto.php';
require_once __DIR__ . '/SmtpMailer.php';

function getSmtpSettings(): array
{
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM smtp_settings WHERE id = 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $defaults = [
        'enabled' => 0,
        'host' => 'smtp.office365.com',
        'port' => 587,
        'encryption' => 'starttls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'TimePoint',
    ];

    $settings = array_merge($defaults, $settings);
    $settings['password_plain'] = tpDecrypt($settings['password'] ?? '');

    return $settings;
}

function saveSmtpSettings(array $data): void
{
    global $conn;

    $current = getSmtpSettings();
    $password = trim((string)($data['password'] ?? ''));
    $encryptedPassword = $password !== '' ? tpEncrypt($password) : ($current['password'] ?? '');

    $stmt = $conn->prepare("
        INSERT INTO smtp_settings (id, enabled, host, port, encryption, username, password, from_email, from_name, updated_at)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ON CONFLICT(id) DO UPDATE SET
            enabled = excluded.enabled,
            host = excluded.host,
            port = excluded.port,
            encryption = excluded.encryption,
            username = excluded.username,
            password = excluded.password,
            from_email = excluded.from_email,
            from_name = excluded.from_name,
            updated_at = excluded.updated_at
    ");

    $stmt->execute([
        !empty($data['enabled']) ? 1 : 0,
        trim((string)($data['host'] ?? 'smtp.office365.com')),
        max(1, (int)($data['port'] ?? 587)),
        in_array(($data['encryption'] ?? 'starttls'), ['none', 'starttls', 'tls', 'ssl'], true) ? $data['encryption'] : 'starttls',
        trim((string)($data['username'] ?? '')),
        $encryptedPassword,
        trim((string)($data['from_email'] ?? '')),
        trim((string)($data['from_name'] ?? 'TimePoint')),
    ]);
}

function sendTimePointMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): void
{
    $settings = getSmtpSettings();
    if ((int)$settings['enabled'] !== 1) {
        throw new RuntimeException('SMTP-Versand ist deaktiviert.');
    }

    $mailer = new SmtpMailer($settings);
    $mailer->send($toEmail, $toName, $subject, $htmlBody, $textBody, $attachments);
}

function createPasswordResetToken(int $userId): array
{
    global $conn;

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE user_id = ? AND used_at IS NULL");
    $stmt->execute([$userId]);

    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $tokenHash, $expiresAt]);

    return [
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function findPasswordResetToken(string $token): ?object
{
    global $conn;

    if ($token === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT password_resets.*, users.username, users.email
        FROM password_resets
        INNER JOIN users ON users.id = password_resets.user_id
        WHERE password_resets.token_hash = ?
          AND password_resets.used_at IS NULL
          AND password_resets.expires_at >= datetime('now')
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch(PDO::FETCH_OBJ);

    return $reset ?: null;
}

function markPasswordResetTokenUsed(int $resetId): void
{
    global $conn;

    $stmt = $conn->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE id = ?");
    $stmt->execute([$resetId]);
}

function buildTimePointUrl(string $path, array $query = []): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/TimePoint/index.php')), '/');
    $url = $scheme . '://' . $host . ($basePath === '' ? '' : $basePath) . '/' . ltrim($path, '/');

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function sendPasswordResetMail(object $user, string $resetUrl): void
{
    $subject = 'TimePoint Passwort zuruecksetzen';
    $safeName = htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $html = "
        <p>Hallo {$safeName},</p>
        <p>fuer dein TimePoint-Konto wurde ein Passwort-Reset angefordert.</p>
        <p><a href=\"{$safeUrl}\">Passwort jetzt zuruecksetzen</a></p>
        <p>Der Link ist 60 Minuten gueltig. Falls du die Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.</p>
    ";
    $text = "Hallo {$user->username},\n\n"
        . "fuer dein TimePoint-Konto wurde ein Passwort-Reset angefordert.\n"
        . "Link: {$resetUrl}\n\n"
        . "Der Link ist 60 Minuten gueltig. Falls du die Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.";

    sendTimePointMail($user->email, $user->username, $subject, $html, $text);
}
