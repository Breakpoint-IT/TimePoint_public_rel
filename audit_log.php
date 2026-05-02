<?php
ob_start();
include 'header.php';
$headerHtml = ob_get_clean();
$headerHtml = preg_replace('/\s*<\/body>\s*<\/html>\s*$/i', '', $headerHtml);
echo $headerHtml;

require_once __DIR__ . '/app/audit/AuditLog.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$currentUserId = (int)$_SESSION['user_id'];
$currentRole = tpAuditResolveRole($currentUserId);
$_SESSION['role'] = $currentRole;
$auditError = '';
$chainStatus = ['valid' => false, 'failed_id' => null, 'message' => 'Audit-Pruefung nicht ausgefuehrt'];
$totalAuditEntries = 0;
$entries = [];

try {
    $chainStatus = tpAuditVerifyChain();
    $totalStmt = $conn->query('SELECT COUNT(*) FROM audit_log');
    $totalAuditEntries = (int)($totalStmt ? $totalStmt->fetchColumn() : 0);

    $entries = tpAuditVisibleEntries($currentUserId, $currentRole, 200);
} catch (Throwable $e) {
    $auditError = $e->getMessage();
}

function auditPrettyJson(?string $json): string
{
    if ($json === null || $json === '') {
        return '-';
    }

    $decoded = json_decode($json, true);
    if ($decoded === null) {
        return $json;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>

<div class="min-h-screen bg-base-200">
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col lg:flex-row gap-4 justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold">Audit Log</h1>
                <p class="text-sm opacity-70">
                    Die letzten 200 protokollierten Änderungen mit Hash-Kettenprüfung.
                    Sichtbare Einträge: <?= count($entries) ?> · Gesamt: <?= $totalAuditEntries ?> · Rolle: <?= htmlspecialchars($currentRole) ?> · Benutzer-ID: <?= $currentUserId ?>
                </p>
            </div>
            <div class="flex flex-col gap-3 items-start lg:items-end">
                <?php if ($currentRole === 'admin' || $currentRole === 'administrator') : ?>
                    <div class="flex gap-2">
                        <a href="audit_export.php?format=csv" class="btn btn-outline btn-sm">
                            <i class="fas fa-file-csv mr-2"></i>CSV Export
                        </a>
                        <a href="audit_export.php?format=json" class="btn btn-outline btn-sm">
                            <i class="fas fa-file-code mr-2"></i>JSON Export
                        </a>
                        <a href="audit_export.php?format=sql" class="btn btn-outline btn-sm">
                            <i class="fas fa-database mr-2"></i>SQL Export
                        </a>
                    </div>
                <?php endif; ?>
                <div class="alert <?= $chainStatus['valid'] ? 'alert-success' : 'alert-error' ?> max-w-xl">
                    <i class="fas <?= $chainStatus['valid'] ? 'fa-shield-alt' : 'fa-triangle-exclamation' ?>"></i>
                    <span>
                        <?= $chainStatus['valid'] ? 'Hash-Kette gültig' : 'Hash-Kette fehlerhaft ab Eintrag #' . htmlspecialchars((string)$chainStatus['failed_id']) ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($auditError !== '') : ?>
            <div class="alert alert-error mb-6">
                <i class="fas fa-triangle-exclamation"></i>
                <span>Audit Log konnte nicht geladen werden: <?= htmlspecialchars($auditError) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($auditError === '') : ?>
            <div class="grid gap-4 lg:grid-cols-3 mb-6">
                <div class="stats shadow bg-base-100">
                    <div class="stat">
                        <div class="stat-title">Geprüfte Einträge</div>
                        <div class="stat-value text-2xl"><?= htmlspecialchars((string)($chainStatus['entries_checked'] ?? 0)) ?></div>
                    </div>
                </div>
                <div class="stats shadow bg-base-100">
                    <div class="stat">
                        <div class="stat-title">Letzter gültiger Eintrag</div>
                        <div class="stat-value text-2xl"><?= htmlspecialchars((string)($chainStatus['last_valid_id'] ?? '-')) ?></div>
                    </div>
                </div>
                <div class="stats shadow bg-base-100">
                    <div class="stat">
                        <div class="stat-title">Prüfstatus</div>
                        <div class="stat-value text-2xl <?= $chainStatus['valid'] ? 'text-success' : 'text-error' ?>">
                            <?= $chainStatus['valid'] ? 'OK' : 'Fehler' ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <div class="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between">
                        <div>
                            <h2 class="card-title">Kettenstatus</h2>
                            <p class="text-sm opacity-70"><?= htmlspecialchars((string)($chainStatus['message'] ?? '')) ?></p>
                        </div>
                        <?php if ($currentRole === 'admin') : ?>
                            <div>
                                <a href="audit_chain_admin.php" class="btn btn-outline btn-sm">
                                    <i class="fas fa-shield-halved mr-2"></i>Prüfdetails öffnen
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$chainStatus['valid'] && !empty($chainStatus['details'])) : ?>
                        <div class="mt-4 overflow-x-auto">
                            <table class="table table-sm">
                                <tbody>
                                    <tr>
                                        <th>Fehlercode</th>
                                        <td><code><?= htmlspecialchars((string)($chainStatus['reason_code'] ?? 'unknown')) ?></code></td>
                                    </tr>
                                    <tr>
                                        <th>Betroffener Eintrag</th>
                                        <td>#<?= htmlspecialchars((string)($chainStatus['details']['entry_id'] ?? $chainStatus['failed_id'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Aktion</th>
                                        <td><?= htmlspecialchars((string)($chainStatus['details']['action'] ?? '-')) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Bearbeiter</th>
                                        <td><?= htmlspecialchars((string)($chainStatus['details']['actor_username'] ?? '-')) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Zeitpunkt</th>
                                        <td><?= htmlspecialchars((string)($chainStatus['details']['created_at'] ?? '-')) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Zeitpunkt</th>
                                <th>Aktion</th>
                                <th>Bearbeiter</th>
                                <th>Mitarbeiter</th>
                                <th>Grund</th>
                                <th>Details</th>
                                <th>Hash</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry) : ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$entry['id']) ?></td>
                                    <td><?= htmlspecialchars((string)$entry['created_at']) ?></td>
                                    <td>
                                        <span class="badge badge-outline"><?= htmlspecialchars($entry['action']) ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($entry['actor_username'] ?? '-') ?><br>
                                        <span class="text-xs opacity-60"><?= htmlspecialchars($entry['actor_role'] ?? '') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($entry['target_username'] ?? ('#' . $entry['target_user_id'])) ?></td>
                                    <td class="max-w-xs whitespace-normal"><?= htmlspecialchars($entry['reason'] ?: '-') ?></td>
                                    <td>
                                        <details class="collapse collapse-arrow bg-base-200 min-w-72">
                                            <summary class="collapse-title text-sm">Alt / Neu</summary>
                                            <div class="collapse-content">
                                                <div class="text-xs font-semibold mb-1">Alt</div>
                                                <pre class="text-xs whitespace-pre-wrap"><?= htmlspecialchars(auditPrettyJson($entry['old_values'])) ?></pre>
                                                <div class="text-xs font-semibold mt-3 mb-1">Neu</div>
                                                <pre class="text-xs whitespace-pre-wrap"><?= htmlspecialchars(auditPrettyJson($entry['new_values'])) ?></pre>
                                            </div>
                                        </details>
                                    </td>
                                    <td>
                                        <code class="text-xs"><?= htmlspecialchars(substr($entry['entry_hash'], 0, 12)) ?></code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$entries) : ?>
                                <tr>
                                    <td colspan="8" class="text-center opacity-70">
                                        <?= $totalAuditEntries > 0 ? 'Keine Audit-Einträge für diese Ansicht sichtbar.' : 'Noch keine Audit-Einträge vorhanden.' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
