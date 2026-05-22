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
$chainStatus = ['valid' => false, 'failed_id' => null, 'message' => AUDIT_MESSAGE_NOT_RUN, 'reason_code' => 'not_run'];
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

function auditChainMessage(array $chainStatus): string
{
    $messages = [
        'empty_log' => AUDIT_MESSAGE_EMPTY_LOG,
        'previous_hash_mismatch' => AUDIT_MESSAGE_PREVIOUS_HASH_MISMATCH,
        'entry_hash_mismatch' => AUDIT_MESSAGE_ENTRY_HASH_MISMATCH,
        'hmac_mismatch' => AUDIT_MESSAGE_HMAC_MISMATCH,
        'ok' => AUDIT_MESSAGE_OK,
        'not_run' => AUDIT_MESSAGE_NOT_RUN,
    ];

    return $messages[$chainStatus['reason_code'] ?? ''] ?? (string)($chainStatus['message'] ?? '');
}
?>

<div class="min-h-screen bg-base-200">
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col lg:flex-row gap-4 justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold"><?= AUDIT_LOG_TITLE ?></h1>
                <p class="text-sm opacity-70">
                    <?= AUDIT_LOG_DESCRIPTION ?>
                    <?= AUDIT_VISIBLE_ENTRIES ?>: <?= count($entries) ?> · <?= AUDIT_TOTAL_ENTRIES ?>: <?= $totalAuditEntries ?> · <?= AUDIT_ROLE ?>: <?= htmlspecialchars($currentRole) ?> · <?= AUDIT_USER_ID ?>: <?= $currentUserId ?>
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
                        <?= $chainStatus['valid'] ? AUDIT_HASH_CHAIN_VALID : sprintf(AUDIT_HASH_CHAIN_INVALID_FROM, htmlspecialchars((string)$chainStatus['failed_id'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($auditError !== '') : ?>
            <div class="alert alert-error mb-6">
                <i class="fas fa-triangle-exclamation"></i>
                <span><?= sprintf(AUDIT_LOG_LOAD_ERROR, htmlspecialchars($auditError)) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($auditError === '') : ?>
            <div class="grid gap-4 lg:grid-cols-3 mb-6">
                <div class="stats shadow bg-base-100">
                    <div class="stat">
                        <div class="stat-title"><?= AUDIT_CHECKED_ENTRIES ?></div>
                        <div class="stat-value text-2xl"><?= htmlspecialchars((string)($chainStatus['entries_checked'] ?? 0)) ?></div>
                    </div>
                </div>
                <div class="stats shadow bg-base-100">
                    <div class="stat">
                        <div class="stat-title"><?= AUDIT_LAST_VALID_ENTRY ?></div>
                        <div class="stat-value text-2xl"><?= htmlspecialchars((string)($chainStatus['last_valid_id'] ?? '-')) ?></div>
                    </div>
                </div>
                <div class="stats shadow bg-base-100">
                    <div class="stat">
                        <div class="stat-title"><?= AUDIT_CHECK_STATUS ?></div>
                        <div class="stat-value text-2xl <?= $chainStatus['valid'] ? 'text-success' : 'text-error' ?>">
                            <?= $chainStatus['valid'] ? 'OK' : AUDIT_ERROR ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <div class="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between">
                        <div>
                            <h2 class="card-title"><?= AUDIT_CHAIN_STATUS ?></h2>
                            <p class="text-sm opacity-70"><?= htmlspecialchars(auditChainMessage($chainStatus)) ?></p>
                        </div>
                        <?php if ($currentRole === 'admin') : ?>
                            <div>
                                <a href="audit_chain_admin.php" class="btn btn-outline btn-sm">
                                    <i class="fas fa-shield-halved mr-2"></i><?= AUDIT_OPEN_CHECK_DETAILS ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$chainStatus['valid'] && !empty($chainStatus['details'])) : ?>
                        <div class="mt-4 overflow-x-auto">
                            <table class="table table-sm">
                                <tbody>
                                    <tr>
                                        <th><?= AUDIT_ERROR_CODE ?></th>
                                        <td><code><?= htmlspecialchars((string)($chainStatus['reason_code'] ?? 'unknown')) ?></code></td>
                                    </tr>
                                    <tr>
                                        <th><?= AUDIT_AFFECTED_ENTRY ?></th>
                                        <td>#<?= htmlspecialchars((string)($chainStatus['details']['entry_id'] ?? $chainStatus['failed_id'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= AUDIT_ACTION ?></th>
                                        <td><?= htmlspecialchars((string)($chainStatus['details']['action'] ?? '-')) ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= AUDIT_ACTOR ?></th>
                                        <td><?= htmlspecialchars((string)($chainStatus['details']['actor_username'] ?? '-')) ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= AUDIT_TIMESTAMP ?></th>
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
                                <th><?= AUDIT_TIMESTAMP ?></th>
                                <th><?= AUDIT_ACTION ?></th>
                                <th><?= AUDIT_ACTOR ?></th>
                                <th><?= AUDIT_EMPLOYEE ?></th>
                                <th><?= AUDIT_REASON ?></th>
                                <th><?= AUDIT_DETAILS ?></th>
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
                                            <summary class="collapse-title text-sm"><?= AUDIT_OLD_NEW ?></summary>
                                            <div class="collapse-content">
                                                <div class="text-xs font-semibold mb-1"><?= AUDIT_OLD ?></div>
                                                <pre class="text-xs whitespace-pre-wrap"><?= htmlspecialchars(auditPrettyJson($entry['old_values'])) ?></pre>
                                                <div class="text-xs font-semibold mt-3 mb-1"><?= AUDIT_NEW ?></div>
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
                                        <?= $totalAuditEntries > 0 ? AUDIT_NO_VISIBLE_ENTRIES : AUDIT_NO_ENTRIES ?>
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
