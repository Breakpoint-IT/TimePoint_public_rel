<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Not authorized');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/pdf/TimeReportPdf.php';

$currentUserId = (int)$_SESSION['user_id'];
$currentRole = $_SESSION['role'] ?? 'user';

function pdfCanExportUser(int $targetUserId): bool
{
    global $conn, $currentUserId, $currentRole;

    if ($targetUserId === $currentUserId) {
        return true;
    }

    if ($currentRole === 'admin') {
        return true;
    }

    if ($currentRole !== 'supervisor') {
        return false;
    }

    $stmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND supervisor_id = ?');
    $stmt->execute([$targetUserId, $currentUserId]);

    return (int)$stmt->fetchColumn() > 0;
}

function pdfManagedUserIds(): array
{
    global $conn, $currentUserId, $currentRole;

    if ($currentRole === 'admin') {
        $stmt = $conn->prepare('SELECT id FROM users WHERE id != ? ORDER BY username');
        $stmt->execute([$currentUserId]);
    } elseif ($currentRole === 'supervisor') {
        $stmt = $conn->prepare('SELECT id FROM users WHERE supervisor_id = ? ORDER BY username');
        $stmt->execute([$currentUserId]);
    } else {
        return [$currentUserId];
    }

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$mode = $_GET['mode'] ?? 'user';
$pdfA = isset($_GET['pdfa']) && $_GET['pdfa'] === '1';
$summaryOnly = $mode === 'all';
$targetUserIds = [];
$filenameUser = null;

if ($summaryOnly) {
    if ($currentRole !== 'admin' && $currentRole !== 'supervisor') {
        http_response_code(403);
        die('Not authorized');
    }
    $targetUserIds = pdfManagedUserIds();
} else {
    $targetUserId = (int)($_GET['user_id'] ?? $currentUserId);
    if (!pdfCanExportUser($targetUserId)) {
        http_response_code(403);
        die('Not authorized');
    }
    $targetUserIds = [$targetUserId];
    $filenameUser = tpPdfFetchUser($targetUserId);
}

$pdf = buildTimePointReportPdf($targetUserIds, $pdfA, $summaryOnly);
$filename = buildTimePointReportFilename($filenameUser, $pdfA, $summaryOnly);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
