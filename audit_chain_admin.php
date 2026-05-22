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

if ($currentRole !== 'admin' && $currentRole !== 'administrator') {
    header('Location: audit_log.php');
    exit();
}

$auditError = '';
$chainStatus = [];
$recentEntries = [];

try {
    $chainStatus = tpAuditAnalyzeChain();
    $recentEntries = tpAuditVisibleEntries($currentUserId, $currentRole, 20);
} catch (Throwable $e) {
    $auditError = $e->getMessage();
}

function auditAdminValue($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return (string)$value;
}

function auditAdminChainMessage(array $chainStatus): string
{
    $messages = [
        'empty_log' => AUDIT_MESSAGE_EMPTY_LOG,
        'previous_hash_mismatch' => AUDIT_MESSAGE_PREVIOUS_HASH_MISMATCH,
        'entry_hash_mismatch' => AUDIT_MESSAGE_ENTRY_HASH_MISMATCH,
        'hmac_mismatch' => AUDIT_MESSAGE_HMAC_MISMATCH,
        'ok' => AUDIT_MESSAGE_OK,
    ];

    return $messages[$chainStatus['reason_code'] ?? ''] ?? (string)($chainStatus['message'] ?? '');
}
?>

<div class="min-h-screen bg-base-200">
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col lg:flex-row gap-4 justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold"><?= AUDIT_CHAIN_CHECK_TITLE ?></h1>
                <p class="text-sm opacity-70"><?= AUDIT_CHAIN_DESCRIPTION ?></p>
            </div>
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
                <a href="audit_log.php" class="btn btn-ghost btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i><?= AUDIT_TO_LOG ?>
                </a>
            </div>
        </div>

        <?php if ($auditError !== '') : ?>
            <div class="alert alert-error mb-6">
                <i class="fas fa-triangle-exclamation"></i>
                <span><?= sprintf(AUDIT_CHAIN_LOAD_ERROR, htmlspecialchars($auditError)) ?></span>
            </div>
        <?php else : ?>
            <div class="grid gap-4 lg:grid-cols-4 mb-6">
                <div class="stats shadow bg-base-100">
                    <div class="stat">
                        <div class="stat-title"><?= AUDIT_STATUS ?></div>
                        <div class="stat-value text-2xl <?= $chainStatus['valid'] ? 'text-success' : 'text-error' ?>">
                            <?= $chainStatus['valid'] ? 'OK' : AUDIT_ERROR ?>
                        </div>
                    </div>
                </div>
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
                        <div class="stat-title"><?= AUDIT_FAILED_ID ?></div>
                        <div class="stat-value text-2xl"><?= htmlspecialchars((string)($chainStatus['failed_id'] ?? '-')) ?></div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <h2 class="card-title"><?= AUDIT_SUMMARY ?></h2>
                    <p><?= htmlspecialchars(auditAdminChainMessage($chainStatus)) ?></p>
                    <div class="overflow-x-auto mt-3">
                        <table class="table table-sm">
                            <tbody>
                                <tr>
                                    <th><?= AUDIT_ERROR_CODE ?></th>
                                    <td><code><?= htmlspecialchars((string)($chainStatus['reason_code'] ?? 'unknown')) ?></code></td>
                                </tr>
                                <tr>
                                    <th><?= AUDIT_AFFECTED_ENTRY ?></th>
                                    <td>#<?= htmlspecialchars((string)($chainStatus['details']['entry_id'] ?? ($chainStatus['failed_id'] ?? '-'))) ?></td>
                                </tr>
                                <tr>
                                    <th><?= AUDIT_ACTION ?></th>
                                    <td><?= htmlspecialchars(auditAdminValue($chainStatus['details']['action'] ?? null)) ?></td>
                                </tr>
                                <tr>
                                    <th><?= AUDIT_ACTOR ?></th>
                                    <td><?= htmlspecialchars(auditAdminValue($chainStatus['details']['actor_username'] ?? null)) ?></td>
                                </tr>
                                <tr>
                                    <th><?= AUDIT_TIMESTAMP ?></th>
                                    <td><?= htmlspecialchars(auditAdminValue($chainStatus['details']['created_at'] ?? null)) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if (!$chainStatus['valid'] && !empty($chainStatus['details'])) : ?>
                <div class="card bg-base-100 shadow-xl mb-6">
                    <div class="card-body">
                        <h2 class="card-title"><?= AUDIT_CHECK_DETAILS ?></h2>
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <tbody>
                                    <?php foreach ($chainStatus['details'] as $key => $value) : ?>
                                        <tr>
                                            <th><?= htmlspecialchars((string)$key) ?></th>
                                            <td><code class="text-xs break-all"><?= htmlspecialchars(auditAdminValue($value)) ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <h2 class="card-title"><?= AUDIT_ERROR_PROCEDURE ?></h2>
                    <div class="space-y-2 text-sm leading-6">
                        <p><?= AUDIT_PROCEDURE_STEP_1 ?></p>
                        <p><?= AUDIT_PROCEDURE_STEP_2 ?></p>
                        <p><?= AUDIT_PROCEDURE_STEP_3 ?></p>
                        <p><?= AUDIT_PROCEDURE_STEP_4 ?></p>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title"><?= AUDIT_RECENT_ENTRIES ?></h2>
                    <div class="overflow-x-auto">
                        <table class="table table-zebra w-full">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th><?= AUDIT_TIMESTAMP ?></th>
                                    <th><?= AUDIT_ACTION ?></th>
                                    <th><?= AUDIT_ACTOR ?></th>
                                    <th><?= AUDIT_EMPLOYEE ?></th>
                                    <th>Hash</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEntries as $entry) : ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)$entry['id']) ?></td>
                                        <td><?= htmlspecialchars((string)$entry['created_at']) ?></td>
                                        <td><?= htmlspecialchars((string)$entry['action']) ?></td>
                                        <td><?= htmlspecialchars((string)($entry['actor_username'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string)($entry['target_username'] ?? ('#' . ($entry['target_user_id'] ?? '-')))) ?></td>
                                        <td><code class="text-xs"><?= htmlspecialchars(substr((string)$entry['entry_hash'], 0, 16)) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($recentEntries === []) : ?>
                                    <tr>
                                        <td colspan="6" class="text-center opacity-70"><?= AUDIT_NO_ENTRIES ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
