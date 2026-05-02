<?php
include 'config.php';
require_once __DIR__ . '/app/audit/AuditLog.php';
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get the raw POST data
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

if (isset($data['ids']) && is_array($data['ids'])) {
    // Delete multiple records
    $ids = $data['ids'];
    $deletedCount = 0;

    try {
        $conn->beginTransaction();
        foreach ($ids as $id) {
            $record = tpAuditFetchTimeRecord((int)$id);
            if (!$record || (int)$record['user_id'] !== (int)$user_id) {
                continue;
            }

            $stmt = $conn->prepare("DELETE FROM zeiterfassung WHERE id = ? AND user_id = ?");
            $stmt->execute([(int)$id, $user_id]);
            if ($stmt->rowCount() > 0) {
                tpAuditRecord('delete', 'zeiterfassung', (int)$id, (int)$user_id, $record, null, '');
                $deletedCount++;
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Audit-Log konnte nicht geschrieben werden']);
        exit();
    }

    if ($deletedCount > 0) {
        echo json_encode(['success' => true, 'message' => "$deletedCount record(s) deleted successfully"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No records were deleted. They may not exist or you don\'t have permission.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided']);
}
?>
