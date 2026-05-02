<?php

function tpAuditFetchActor(?int $actorUserId): array
{
    global $conn;

    if (!$actorUserId) {
        return [
            'id' => null,
            'username' => 'System',
            'role' => 'system',
        ];
    }

    $stmt = $conn->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $stmt->execute([$actorUserId]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);

    return $actor ?: [
        'id' => $actorUserId,
        'username' => 'Unbekannt',
        'role' => '',
    ];
}

function tpAuditResolveRole(int $userId): string
{
    global $conn;

    $stmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();

    return strtolower(trim((string)($role !== false ? $role : 'user')));
}

function tpAuditManagedUserIds(int $userId, string $role): array
{
    global $conn;

    if ($role === 'admin' || $role === 'administrator') {
        $stmt = $conn->query('SELECT id FROM users');
        $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        return array_map('intval', $ids);
    }

    if ($role !== 'supervisor') {
        return [$userId];
    }

    $stmt = $conn->prepare('SELECT id FROM users WHERE supervisor_id = ?');
    $stmt->execute([$userId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $ids[] = $userId;

    return array_values(array_unique(array_map('intval', $ids)));
}

function tpAuditEntryUserIds(array $entry): array
{
    $userIds = [];

    foreach (['target_user_id', 'actor_user_id'] as $key) {
        if (isset($entry[$key]) && is_numeric($entry[$key])) {
            $userIds[] = (int)$entry[$key];
        }
    }

    foreach (['old_values', 'new_values'] as $key) {
        if (empty($entry[$key])) {
            continue;
        }

        $decoded = json_decode((string)$entry[$key], true);
        if (!is_array($decoded)) {
            continue;
        }

        foreach (['user_id', 'target_user_id', 'actor_user_id'] as $jsonKey) {
            if (isset($decoded[$jsonKey]) && is_numeric($decoded[$jsonKey])) {
                $userIds[] = (int)$decoded[$jsonKey];
            }
        }
    }

    return array_values(array_unique(array_filter($userIds)));
}

function tpAuditCanUserViewEntry(array $entry, int $userId, string $role): bool
{
    if ($role === 'admin' || $role === 'administrator') {
        return true;
    }

    $allowedUserIds = tpAuditManagedUserIds($userId, $role);
    foreach (tpAuditEntryUserIds($entry) as $entryUserId) {
        if (in_array($entryUserId, $allowedUserIds, true)) {
            return true;
        }
    }

    return false;
}

function tpAuditVisibleEntries(int $userId, string $role, int $limit = 200): array
{
    global $conn;

    $stmt = $conn->query("
        SELECT audit_log.*, target.username AS target_username
        FROM audit_log
        LEFT JOIN users AS target ON target.id = audit_log.target_user_id
        ORDER BY audit_log.id DESC
    ");
    $entries = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $visibleEntries = [];

    foreach ($entries as $entry) {
        if (!tpAuditCanUserViewEntry($entry, $userId, $role)) {
            continue;
        }

        if (($entry['target_username'] ?? null) === null) {
            $entryUserIds = tpAuditEntryUserIds($entry);
            foreach ($entryUserIds as $entryUserId) {
                if ($entryUserId === (int)($entry['actor_user_id'] ?? 0) && $role !== 'user') {
                    continue;
                }

                $userStmt = $conn->prepare('SELECT username FROM users WHERE id = ?');
                $userStmt->execute([$entryUserId]);
                $fallbackUsername = $userStmt->fetchColumn();
                if ($fallbackUsername !== false) {
                    $entry['target_username'] = (string)$fallbackUsername;
                    break;
                }
            }
        }

        $visibleEntries[] = $entry;
        if (count($visibleEntries) >= $limit) {
            break;
        }
    }

    return $visibleEntries;
}

function tpAuditContext(): array
{
    return [
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
}

function tpAuditNormalize($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
    if (!$isList) {
        ksort($value);
    }

    foreach ($value as $key => $item) {
        $value[$key] = tpAuditNormalize($item);
    }

    return $value;
}

function tpAuditJson($value): string
{
    return json_encode(tpAuditNormalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function tpAuditLatestHash(): string
{
    global $conn;

    $stmt = $conn->query('SELECT entry_hash FROM audit_log ORDER BY id DESC LIMIT 1');
    $hash = $stmt ? $stmt->fetchColumn() : false;

    return $hash !== false ? (string)$hash : str_repeat('0', 64);
}

function tpAuditRecord(string $action, string $entityType, ?int $entityId, ?int $targetUserId, $oldValues, $newValues, string $reason = ''): void
{
    global $conn;

    $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $actor = tpAuditFetchActor($actorUserId);
    $context = tpAuditContext();
    $createdAt = date('Y-m-d H:i:s');
    $previousHash = tpAuditLatestHash();

    $actorId = $actor['id'] !== null ? (int)$actor['id'] : null;
    $entityId = $entityId !== null ? (int)$entityId : null;
    $targetUserId = $targetUserId !== null ? (int)$targetUserId : null;

    $payload = [
        'created_at' => $createdAt,
        'actor_user_id' => $actorId,
        'actor_username' => $actor['username'],
        'actor_role' => $actor['role'],
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'target_user_id' => $targetUserId,
        'old_values' => $oldValues,
        'new_values' => $newValues,
        'reason' => $reason,
        'ip_address' => $context['ip_address'],
        'user_agent' => $context['user_agent'],
        'previous_hash' => $previousHash,
    ];

    $entryHash = hash('sha256', $previousHash . '|' . tpAuditJson($payload));
    $hmacKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
    $hmacSignature = hash_hmac('sha256', $entryHash, $hmacKey);

    $stmt = $conn->prepare('
        INSERT INTO audit_log (
            created_at, actor_user_id, actor_username, actor_role, action, entity_type, entity_id,
            target_user_id, old_values, new_values, reason, ip_address, user_agent,
            previous_hash, entry_hash, hmac_signature
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $createdAt,
        $actorId,
        $actor['username'],
        $actor['role'],
        $action,
        $entityType,
        $entityId,
        $targetUserId,
        $oldValues === null ? null : tpAuditJson($oldValues),
        $newValues === null ? null : tpAuditJson($newValues),
        $reason,
        $context['ip_address'],
        $context['user_agent'],
        $previousHash,
        $entryHash,
        $hmacSignature,
    ]);
}

function tpAuditFetchTimeRecord(int $recordId): ?array
{
    global $conn;

    $stmt = $conn->prepare('SELECT id, startzeit, endzeit, pause, beschreibung, standort, user_id FROM zeiterfassung WHERE id = ?');
    $stmt->execute([$recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return $record ?: null;
}

function tpAuditPayloadFromEntry(array $entry): array
{
    return [
        'created_at' => $entry['created_at'],
        'actor_user_id' => $entry['actor_user_id'] !== null ? (int)$entry['actor_user_id'] : null,
        'actor_username' => $entry['actor_username'],
        'actor_role' => $entry['actor_role'],
        'action' => $entry['action'],
        'entity_type' => $entry['entity_type'],
        'entity_id' => $entry['entity_id'] !== null ? (int)$entry['entity_id'] : null,
        'target_user_id' => $entry['target_user_id'] !== null ? (int)$entry['target_user_id'] : null,
        'old_values' => $entry['old_values'] !== null ? json_decode((string)$entry['old_values'], true) : null,
        'new_values' => $entry['new_values'] !== null ? json_decode((string)$entry['new_values'], true) : null,
        'reason' => $entry['reason'],
        'ip_address' => $entry['ip_address'],
        'user_agent' => $entry['user_agent'],
        'previous_hash' => $entry['previous_hash'],
    ];
}

function tpAuditAnalyzeChain(): array
{
    global $conn;

    $stmt = $conn->query('SELECT * FROM audit_log ORDER BY id ASC');
    $entries = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $previousHash = str_repeat('0', 64);
    $entriesChecked = 0;
    $lastValidId = null;

    if ($entries === []) {
        return [
            'valid' => true,
            'failed_id' => null,
            'last_valid_id' => null,
            'entries_checked' => 0,
            'message' => 'Keine Audit-Eintraege vorhanden',
            'reason_code' => 'empty_log',
            'details' => [],
        ];
    }

    foreach ($entries as $entry) {
        $entriesChecked++;
        $entryId = (int)$entry['id'];
        $details = [
            'entry_id' => $entryId,
            'created_at' => (string)$entry['created_at'],
            'action' => (string)$entry['action'],
            'actor_username' => (string)($entry['actor_username'] ?? ''),
            'target_user_id' => $entry['target_user_id'] !== null ? (int)$entry['target_user_id'] : null,
        ];

        if ((string)$entry['previous_hash'] !== $previousHash) {
            $details['expected_previous_hash'] = $previousHash;
            $details['actual_previous_hash'] = (string)$entry['previous_hash'];

            return [
                'valid' => false,
                'failed_id' => $entryId,
                'last_valid_id' => $lastValidId,
                'entries_checked' => $entriesChecked,
                'message' => 'Verkettung unterbrochen: previous_hash passt nicht',
                'reason_code' => 'previous_hash_mismatch',
                'details' => $details,
            ];
        }

        $payload = tpAuditPayloadFromEntry($entry);
        $expectedHash = hash('sha256', $previousHash . '|' . tpAuditJson($payload));
        $expectedHmac = hash_hmac('sha256', $expectedHash, defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '');

        if (!hash_equals($expectedHash, (string)$entry['entry_hash'])) {
            $details['expected_entry_hash'] = $expectedHash;
            $details['actual_entry_hash'] = (string)$entry['entry_hash'];

            return [
                'valid' => false,
                'failed_id' => $entryId,
                'last_valid_id' => $lastValidId,
                'entries_checked' => $entriesChecked,
                'message' => 'Integritaetsfehler: entry_hash passt nicht zum Inhalt',
                'reason_code' => 'entry_hash_mismatch',
                'details' => $details,
            ];
        }

        if (!hash_equals($expectedHmac, (string)$entry['hmac_signature'])) {
            $details['expected_hmac_signature'] = $expectedHmac;
            $details['actual_hmac_signature'] = (string)$entry['hmac_signature'];

            return [
                'valid' => false,
                'failed_id' => $entryId,
                'last_valid_id' => $lastValidId,
                'entries_checked' => $entriesChecked,
                'message' => 'Signaturfehler: HMAC-Signatur passt nicht',
                'reason_code' => 'hmac_mismatch',
                'details' => $details,
            ];
        }

        $previousHash = (string)$entry['entry_hash'];
        $lastValidId = $entryId;
    }

    return [
        'valid' => true,
        'failed_id' => null,
        'last_valid_id' => $lastValidId,
        'entries_checked' => $entriesChecked,
        'message' => 'Hash-Kette gueltig',
        'reason_code' => 'ok',
        'details' => [],
    ];
}

function tpAuditVerifyChain(): array
{
    $analysis = tpAuditAnalyzeChain();

    return [
        'valid' => $analysis['valid'],
        'failed_id' => $analysis['failed_id'],
        'message' => $analysis['message'],
        'reason_code' => $analysis['reason_code'],
        'entries_checked' => $analysis['entries_checked'],
        'last_valid_id' => $analysis['last_valid_id'],
        'details' => $analysis['details'],
    ];
}
