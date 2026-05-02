<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/audit/AuditLog.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authorized');
}

$currentUserId = (int)$_SESSION['user_id'];
$currentRole = tpAuditResolveRole($currentUserId);
$_SESSION['role'] = $currentRole;

if ($currentRole !== 'admin' && $currentRole !== 'administrator') {
    http_response_code(403);
    exit('Not authorized');
}

$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'json', 'sql'], true)) {
    $format = 'csv';
}

$stmt = $conn->query("
    SELECT audit_log.*, target.username AS target_username
    FROM audit_log
    LEFT JOIN users AS target ON target.id = audit_log.target_user_id
    ORDER BY audit_log.id DESC
");
$entries = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$filenameBase = 'audit_log_' . date('Y-m-d_H-i-s');

if ($format === 'sql') {
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.sql"');

    echo "-- TimePoint Audit Log Export\n";
    echo "-- Exported at: " . date('c') . "\n";
    echo "-- Exported by user_id: " . $currentUserId . "\n\n";
    echo "BEGIN TRANSACTION;\n";

    foreach ($entries as $entry) {
        $values = [
            $entry['id'],
            $entry['created_at'],
            $entry['actor_user_id'],
            $entry['actor_username'],
            $entry['actor_role'],
            $entry['action'],
            $entry['entity_type'],
            $entry['entity_id'],
            $entry['target_user_id'],
            $entry['old_values'],
            $entry['new_values'],
            $entry['reason'],
            $entry['ip_address'],
            $entry['user_agent'],
            $entry['previous_hash'],
            $entry['entry_hash'],
            $entry['hmac_signature'],
        ];

        $quotedValues = array_map(static function ($value) use ($conn): string {
            if ($value === null) {
                return 'NULL';
            }

            if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
                return (string)$value;
            }

            return $conn->quote((string)$value);
        }, $values);

        echo "INSERT INTO audit_log (id, created_at, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, target_user_id, old_values, new_values, reason, ip_address, user_agent, previous_hash, entry_hash, hmac_signature) VALUES (" . implode(', ', $quotedValues) . ");\n";
    }

    echo "COMMIT;\n";
    exit();
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.json"');

    echo json_encode([
        'exported_at' => date('c'),
        'exported_by_user_id' => $currentUserId,
        'entry_count' => count($entries),
        'entries' => $entries,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');

$output = fopen('php://temp', 'w+');
fprintf($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'ID',
    'Zeitpunkt',
    'Bearbeiter-ID',
    'Bearbeiter',
    'Rolle Bearbeiter',
    'Aktion',
    'Entitaet',
    'Entitaet-ID',
    'Mitarbeiter-ID',
    'Mitarbeiter',
    'Grund',
    'Altwerte',
    'Neuwerte',
    'IP-Adresse',
    'User-Agent',
    'Vorheriger Hash',
    'Eintrags-Hash',
    'HMAC-Signatur',
], ';', '"', '');

foreach ($entries as $entry) {
    fputcsv($output, [
        $entry['id'],
        $entry['created_at'],
        $entry['actor_user_id'],
        $entry['actor_username'],
        $entry['actor_role'],
        $entry['action'],
        $entry['entity_type'],
        $entry['entity_id'],
        $entry['target_user_id'],
        $entry['target_username'] ?? '',
        $entry['reason'],
        $entry['old_values'],
        $entry['new_values'],
        $entry['ip_address'],
        $entry['user_agent'],
        $entry['previous_hash'],
        $entry['entry_hash'],
        $entry['hmac_signature'],
    ], ';', '"', '');
}

rewind($output);
echo stream_get_contents($output);
fclose($output);
exit();
