<?php
include 'config.php';
require_once __DIR__ . '/app/mail/mail_settings.php';
require_once __DIR__ . '/app/pdf/TimeReportPdf.php';
require_once __DIR__ . '/app/audit/AuditLog.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$lang = $_SESSION['lang'] ?? 'de';
$lang = in_array($lang, ['de', 'en'], true) ? $lang : 'de';
require_once __DIR__ . "/languages/$lang.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(ERROR_NOT_LOGGED_IN);
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

function canManageUser($targetUserId)
{
    global $conn, $user_id, $user_role;

    $targetUserId = (int)$targetUserId;
    if ($targetUserId === (int)$user_id) {
        return true;
    }

    if ($user_role === 'admin') {
        return true;
    }

    if ($user_role !== 'supervisor') {
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND supervisor_id = ?");
    $stmt->execute([$targetUserId, $user_id]);
    return (int)$stmt->fetchColumn() > 0;
}

function getRecordForAccess($recordId)
{
    global $conn;

    $stmt = $conn->prepare("SELECT id, user_id, startzeit, endzeit, pause, standort, beschreibung FROM zeiterfassung WHERE id = ?");
    $stmt->execute([(int)$recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record || !canManageUser($record['user_id'])) {
        return false;
    }

    return $record;
}

function normalizeDateTimeInput($value)
{
    $timestamp = strtotime((string)$value);
    if (!$timestamp) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function sendJsonResponse($success, $message, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

function auditReasonForTimeChange($targetUserId): string
{
    global $user_id, $user_role;

    $reason = trim($_POST['audit_reason'] ?? '');
    $isThirdPartyChange = (int)$targetUserId !== (int)$user_id && in_array($user_role, ['admin', 'supervisor'], true);

    if ($isThirdPartyChange && $reason === '') {
        sendJsonResponse(false, ERROR_REASON_REQUIRED_FOR_CHANGE, 400);
    }

    return $reason;
}

function usernameExists($username, $excludeUserId = null)
{
    global $conn;

    if ($excludeUserId) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, (int)$excludeUserId]);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
    }

    return (int)$stmt->fetchColumn() > 0;
}

// Funktion, um die Mindestpausendauer basierend auf der Arbeitszeit abzurufen
function getPauseDuration($totalHours)
{
    global $conn;
    $stmt = $conn->prepare("SELECT minimum_pause FROM pause_settings WHERE hours_threshold <= ? ORDER BY hours_threshold DESC LIMIT 1");
    $stmt->execute([$totalHours]);
    return $stmt->fetchColumn();
}

function getConfiguredPauseForUser($targetUserId)
{
    global $conn;

    $stmt = $conn->prepare("SELECT automatic_pause_deduction, pause_duration FROM users WHERE id = ?");
    $stmt->execute([(int)$targetUserId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings || (int)($settings['automatic_pause_deduction'] ?? 1) !== 1) {
        return 0;
    }

    return max(0, (int)($settings['pause_duration'] ?? 0));
}

// Löschen eines Eintrags
if (isset($_POST['delete']) && $_POST['delete'] == 'true' && isset($_POST['id'])) {
    $id = $_POST['id'];
    $record = getRecordForAccess($id);
    if (!$record) {
        http_response_code(403);
        echo ERROR_NO_PERMISSION;
        exit;
    }

    $reason = auditReasonForTimeChange((int)$record['user_id']);

    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("DELETE FROM zeiterfassung WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $deleted = $stmt->execute();

        if ($deleted) {
            tpAuditRecord('delete', 'zeiterfassung', (int)$id, (int)$record['user_id'], $record, null, $reason);
            $conn->commit();
        } else {
            $conn->rollBack();
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo ERROR_AUDIT_LOG . $e->getMessage();
        exit;
    }

    if (!empty($deleted)) {
        echo "Successfully deleted";
    } else {
        echo "Error deleting record";
    }
    exit;
}

// Aktualisieren eines Eintrags
if (isset($_POST['update']) && $_POST['update'] == 'true') {
    header('Content-Type: application/json');
    $id = $_POST['id'];
    $column = $_POST['column'];
    $value = $_POST['data'];

    $allowedColumns = ['startzeit', 'endzeit', 'pause', 'standort', 'beschreibung'];

    if (in_array($column, $allowedColumns)) {
        // Fetch existing record
        $record = getRecordForAccess($id);

        if (!$record) {
            http_response_code(403);
            die(ERROR_RECORD_NOT_FOUND);
        }

        if ($column === 'startzeit' || $column === 'endzeit') {
            $value = normalizeDateTimeInput($value);
            if (!$value) {
                http_response_code(400);
                die(ERROR_INVALID_DATE);
            }
        }

        $new_startzeit = ($column === 'startzeit') ? $value : $record['startzeit'];
        $new_endzeit = ($column === 'endzeit') ? $value : $record['endzeit'];

        // Validate that endzeit is not before startzeit
        if ($new_startzeit && $new_endzeit) {
            $start = new DateTime($new_startzeit);
            $end = new DateTime($new_endzeit);
            if ($end < $start) {
                http_response_code(400);
                die(ERROR_END_BEFORE_START);
            }
        }

        if ($column === 'pause') {
            if ((int)$value < 0) {
                http_response_code(400);
                die("Die Pause darf nicht negativ sein.");
            }
        }

        $reason = auditReasonForTimeChange((int)$record['user_id']);

        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE zeiterfassung SET $column = :value WHERE id = :id");
            $stmt->bindParam(':value', $value);
            $stmt->bindParam(':id', $id);
            $updated = $stmt->execute();

            if ($updated) {
                $newRecord = tpAuditFetchTimeRecord((int)$id);
                tpAuditRecord('update', 'zeiterfassung', (int)$id, (int)$record['user_id'], $record, $newRecord, $reason);
                $conn->commit();
            } else {
                $conn->rollBack();
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            http_response_code(500);
            die(ERROR_AUDIT_LOG . $e->getMessage());
        }

        if (!empty($updated)) {
            http_response_code(204);
            exit;
        } else {
            http_response_code(400);
            die(ERROR_UPDATE_DATA);
        }
    } else {
        http_response_code(400);
        echo ERROR_INVALID_COLUMN;
        exit;
    }
}

// Hinzufügen eines neuen Eintrags oder Beenden eines Eintrags
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];
    header('Content-Type: application/json');

    if ($action === 'supervisor_create_user') {
        if ($user_role !== 'admin') {
            sendJsonResponse(false, ERROR_NO_PERMISSION, 403);
        }

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $forcePasswordChange = isset($_POST['force_password_change']) ? 1 : 0;

        if ($username === '' || $email === '' || $password === '') {
            sendJsonResponse(false, ERROR_CREATE_USER_REQUIRED, 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendJsonResponse(false, ERROR_INVALID_EMAIL, 400);
        }
        if (strlen($password) < 8) {
            sendJsonResponse(false, ERROR_PASSWORD_MIN_LENGTH, 400);
        }
        if (usernameExists($username)) {
            sendJsonResponse(false, 'Dieser Benutzername ist bereits vergeben.', 400);
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ((int)$stmt->fetchColumn() > 0) {
            sendJsonResponse(false, 'Diese E-Mail-Adresse ist bereits vergeben.', 400);
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role, supervisor_id, force_password_change) VALUES (?, ?, ?, 'user', ?, ?)");
        $stmt->execute([$username, $hashedPassword, $email, null, $forcePasswordChange]);
        sendJsonResponse(true, SAVE_EMPLOYEE_CREATED);
    }

    if ($action === 'supervisor_update_username') {
        if ($user_role !== 'admin') {
            sendJsonResponse(false, ERROR_NO_PERMISSION, 403);
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newUsername = trim($_POST['username'] ?? '');

        if (tpIsDemoAdminUserId($targetUserId)) {
            sendJsonResponse(false, tpDemoModeMessage(), 403);
        }
        if ($targetUserId <= 0 || !canManageUser($targetUserId) || $targetUserId === (int)$user_id) {
            sendJsonResponse(false, ERROR_NO_PERMISSION_USER, 403);
        }
        if ($newUsername === '') {
            sendJsonResponse(false, 'Der Benutzername darf nicht leer sein.', 400);
        }
        if (usernameExists($newUsername, $targetUserId)) {
            sendJsonResponse(false, 'Dieser Benutzername ist bereits vergeben.', 400);
        }

        $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->execute([$newUsername, $targetUserId]);
        sendJsonResponse(true, 'Benutzername wurde aktualisiert.');
    }

    if ($action === 'mail_pdf_reports') {
        if ($user_role !== 'admin' && $user_role !== 'supervisor') {
            sendJsonResponse(false, ERROR_NO_PERMISSION, 403);
        }

        $rawUserIds = $_POST['user_ids'] ?? [];
        if (!is_array($rawUserIds)) {
            $rawUserIds = [$rawUserIds];
        }

        $targetUserIds = array_values(array_unique(array_filter(array_map('intval', $rawUserIds), static function ($id) {
            return $id > 0;
        })));
        $pdfA = ($_POST['pdf_format'] ?? 'pdf') === 'pdfa';

        if (!$targetUserIds) {
            sendJsonResponse(false, ERROR_SELECT_AT_LEAST_ONE_EMPLOYEE, 400);
        }

        if (count($targetUserIds) > 50) {
            sendJsonResponse(false, ERROR_MAX_50_EMPLOYEES, 400);
        }

        $sent = [];
        $failed = [];
        foreach ($targetUserIds as $targetUserId) {
            if (!canManageUser($targetUserId) || $targetUserId === (int)$user_id) {
                $failed[] = USERNAME . ' #' . $targetUserId . ': ' . ERROR_NO_PERMISSION;
                continue;
            }

            $recipient = tpPdfFetchUser($targetUserId);
            if (!$recipient) {
                $failed[] = USERNAME . ' #' . $targetUserId . ': ' . ERROR_USER_NOT_FOUND;
                continue;
            }

            if (empty($recipient['email']) || !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                $failed[] = $recipient['username'] . ': ' . ERROR_NO_VALID_EMAIL;
                continue;
            }

            try {
                $pdfContent = buildTimePointReportPdf([$targetUserId], $pdfA, false);
                $filename = buildTimePointReportFilename($recipient, $pdfA, false);
                $formatLabel = $pdfA ? 'PDF/A' : 'PDF';
                $safeName = htmlspecialchars($recipient['username'], ENT_QUOTES, 'UTF-8');
                $subject = 'TimePoint Arbeitszeitauswertung';
                $html = "
                    <p>Hallo {$safeName},</p>
                    <p>im Anhang findest du deine aktuelle TimePoint-Arbeitszeitauswertung als {$formatLabel}.</p>
                    <p>Diese E-Mail wurde automatisch von TimePoint erstellt.</p>
                ";
                $text = "Hallo {$recipient['username']},\n\n"
                    . "im Anhang findest du deine aktuelle TimePoint-Arbeitszeitauswertung als {$formatLabel}.\n\n"
                    . "Diese E-Mail wurde automatisch von TimePoint erstellt.";

                sendTimePointMail(
                    $recipient['email'],
                    $recipient['username'],
                    $subject,
                    $html,
                    $text,
                    [[
                        'filename' => $filename,
                        'content_type' => 'application/pdf',
                        'content' => $pdfContent,
                    ]]
                );
                $sent[] = $recipient['username'];
            } catch (Throwable $e) {
                $failed[] = $recipient['username'] . ': ' . $e->getMessage();
            }
        }

        if (!$sent) {
            sendJsonResponse(false, 'Es wurde keine E-Mail versendet. ' . implode('; ', $failed), 400);
        }

        $message = count($sent) . ' E-Mail(s) versendet: ' . implode(', ', $sent);
        if ($failed) {
            $message .= '. Nicht versendet: ' . implode('; ', $failed);
        }

        sendJsonResponse(true, $message);
    }

    if ($action === 'manual_add' || $action === 'update_record') {
        $targetUserId = (int)($_POST['user_id'] ?? $user_id);
        if (!canManageUser($targetUserId)) {
            sendJsonResponse(false, ERROR_NO_PERMISSION_USER, 403);
        }

        $startzeit_iso = normalizeDateTimeInput($_POST['startzeit'] ?? '');
        $endzeit_iso = normalizeDateTimeInput($_POST['endzeit'] ?? '');
        $pause = max(0, (int)($_POST['pause'] ?? 0));
        $standort = trim($_POST['standort'] ?? '');
        $beschreibung = trim($_POST['beschreibung'] ?? '');

        if (!$startzeit_iso || !$endzeit_iso) {
            sendJsonResponse(false, ERROR_START_END_VALID, 400);
        }

        $startzeit = new DateTime($startzeit_iso);
        $endzeit = new DateTime($endzeit_iso);
        if ($endzeit <= $startzeit) {
            sendJsonResponse(false, 'Endzeit muss nach der Startzeit liegen.', 400);
        }

        $workedMinutes = max(0, floor(($endzeit->getTimestamp() - $startzeit->getTimestamp()) / 60) - $pause);
        if ($workedMinutes <= 0) {
            sendJsonResponse(false, ERROR_WORK_TIME_POSITIVE, 400);
        }

        if ($action === 'manual_add') {
            $reason = auditReasonForTimeChange($targetUserId);
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("INSERT INTO zeiterfassung (startzeit, endzeit, pause, standort, beschreibung, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$startzeit_iso, $endzeit_iso, $pause, $standort, $beschreibung, $targetUserId]);
                $recordId = (int)$conn->lastInsertId();
                tpAuditRecord('create', 'zeiterfassung', $recordId, $targetUserId, null, tpAuditFetchTimeRecord($recordId), $reason);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                sendJsonResponse(false, ERROR_AUDIT_LOG_WRITE . $e->getMessage(), 500);
            }
            sendJsonResponse(true, SAVE_TIME_ENTRY_ADDED);
        }

        $recordId = (int)($_POST['id'] ?? 0);
        $record = getRecordForAccess($recordId);
        if (!$record) {
            sendJsonResponse(false, ERROR_RECORD_NOT_FOUND_OR_PERMISSION, 403);
        }

        $targetUserId = (int)$record['user_id'];
        $reason = auditReasonForTimeChange($targetUserId);
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE zeiterfassung SET startzeit = ?, endzeit = ?, pause = ?, standort = ?, beschreibung = ? WHERE id = ?");
            $stmt->execute([$startzeit_iso, $endzeit_iso, $pause, $standort, $beschreibung, $recordId]);
            tpAuditRecord('update', 'zeiterfassung', $recordId, $targetUserId, $record, tpAuditFetchTimeRecord($recordId), $reason);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            sendJsonResponse(false, ERROR_AUDIT_LOG_WRITE . $e->getMessage(), 500);
        }
        sendJsonResponse(true, SAVE_TIME_ENTRY_UPDATED);
    }

    if ($action === 'start') {
        $startzeit_iso = date('Y-m-d H:i:s', strtotime($_POST["startzeit"]));
        $standort = $_POST["standort"] ?? 'office';
        $beschreibung = $_POST["beschreibung"] ?? '';

        $stmt = $conn->prepare("INSERT INTO zeiterfassung (startzeit, standort, beschreibung, user_id) VALUES (:startzeit, :standort, :beschreibung, :user_id)");
        $stmt->bindParam(':startzeit', $startzeit_iso);
        $stmt->bindParam(':standort', $standort);
        $stmt->bindParam(':beschreibung', $beschreibung);
        $stmt->bindParam(':user_id', $user_id);

        try {
            $conn->beginTransaction();
            if ($stmt->execute()) {
                $recordId = (int)$conn->lastInsertId();
                tpAuditRecord('start', 'zeiterfassung', $recordId, (int)$user_id, null, tpAuditFetchTimeRecord($recordId), '');
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => SAVE_START_WORK_SUCCESS
                ]);
            } else {
                $conn->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => SAVE_START_WORK_ERROR
                ]);
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => ERROR_AUDIT_LOG_WRITE . $e->getMessage()
            ]);
        }
        exit();
    } elseif ($action === 'end') {
        $endzeit_iso = date('Y-m-d H:i:s', strtotime($_POST["endzeit"]));

        $stmt = $conn->prepare("SELECT id, startzeit FROM zeiterfassung WHERE user_id = :user_id AND endzeit IS NULL ORDER BY startzeit DESC LIMIT 1");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            $startzeit = new DateTime($record['startzeit']);
            $endzeit = new DateTime($endzeit_iso);
            $pause = getConfiguredPauseForUser($user_id);

            // Validierung: Endzeit darf nicht vor Startzeit liegen
            if ($endzeit < $startzeit) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => ERROR_END_BEFORE_START
                ]);
                exit;
            }

            $stmt = $conn->prepare("UPDATE zeiterfassung SET endzeit = :endzeit, pause = :pause WHERE id = :id");
            $stmt->bindParam(':endzeit', $endzeit_iso);
            $stmt->bindParam(':pause', $pause);
            $stmt->bindParam(':id', $record['id']);

            try {
                $conn->beginTransaction();
                if ($stmt->execute()) {
                    tpAuditRecord('end', 'zeiterfassung', (int)$record['id'], (int)$user_id, $record, tpAuditFetchTimeRecord((int)$record['id']), '');
                    $conn->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => SAVE_END_WORK_SUCCESS
                    ]);
                } else {
                    $conn->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => SAVE_END_WORK_ERROR
                    ]);
                }
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => ERROR_AUDIT_LOG_WRITE . $e->getMessage()
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => ERROR_NO_OPEN_TIME_ENTRY
            ]);
        }
        exit();
    }
}

// Hinzufügen von Sondertagen (Urlaub, Feiertag, Krankheit)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["urlaubStart"]) && isset($_POST["urlaubEnde"]) && isset($_POST["beschreibung"])) {
    $start = new DateTime($_POST["urlaubStart"]);
    $end = new DateTime($_POST["urlaubEnde"]);

    // Validierung: UrlaubEnde darf nicht vor UrlaubStart liegen
    if ($end < $start) {
        http_response_code(400);
        die("Enddatum des Urlaubs darf nicht vor dem Startdatum liegen.");
    }

    $interval = new DateInterval('P1D');
    $daterange = new DatePeriod($start, $interval, $end->modify('+1 day'));

    $eingetragene_tage = 0;

    // Korrigierte Zeile: Verwendung von $_POST['beschreibung'] statt $ereignistyp
    $beschreibung = $_POST["beschreibung"];

    foreach ($daterange as $date) {
        if ($date->format('N') >= 6) {
            continue;  // Skip Saturday (6) and Sunday (7)
        }
        $datum = $date->format("Y-m-d");
        $startzeit_iso = $datum . ' 09:00:00';
        $endzeit_iso = $datum . ' 17:00:00';
        // $beschreibung = $ereignistyp; // Entfernt
        $pause = getConfiguredPauseForUser($user_id);
        $standort = '';

        $conn->beginTransaction();
        $stmt = $conn->prepare("INSERT INTO zeiterfassung (startzeit, endzeit, beschreibung, pause, standort, user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$startzeit_iso, $endzeit_iso, $beschreibung, $pause, $standort, $user_id]);
        $recordId = (int)$conn->lastInsertId();
        tpAuditRecord('create_special_day', 'zeiterfassung', $recordId, (int)$user_id, null, tpAuditFetchTimeRecord($recordId), '');
        $conn->commit();
        $eingetragene_tage++;
    }

    echo sprintf(SAVE_SPECIAL_DAYS_ADDED, $beschreibung, $start->format('d.m.Y'), $end->modify('-1 day')->format('d.m.Y'), $eingetragene_tage);
}

// After processing, ensure no flags are set to display event selection fields

// Sicherstellen, dass der Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Überprüfen, ob es sich um eine AJAX-Anfrage handelt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['column'], $_POST['data'])) {
    $id = intval($_POST['id']);
    $column = $_POST['column'];
    $data = $_POST['data'];

    // Erlaubte Spalten für Updates
    $allowedColumns = ['standort', 'beschreibung'];
    if (!in_array($column, $allowedColumns)) {
        http_response_code(400);
        echo ERROR_INVALID_COLUMN;
        exit();
    }

    $oldRecord = tpAuditFetchTimeRecord($id);
    if (!$oldRecord || (int)$oldRecord['user_id'] !== (int)$user_id) {
        http_response_code(403);
        echo ERROR_NO_PERMISSION;
        exit();
    }

    // Update-Anweisung vorbereiten
    $stmt = $conn->prepare("UPDATE zeiterfassung SET $column = :data WHERE id = :id AND user_id = :user_id");
    $stmt->bindParam(':data', $data, PDO::PARAM_STR);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

    try {
        $conn->beginTransaction();
        if ($stmt->execute()) {
            tpAuditRecord('update', 'zeiterfassung', $id, (int)$user_id, $oldRecord, tpAuditFetchTimeRecord($id), '');
            $conn->commit();
            echo SAVE_SUCCESS;
        } else {
            $conn->rollBack();
            http_response_code(500);
            echo SAVE_UPDATE_ERROR;
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo "Datenbankfehler: " . $e->getMessage();
    }

    exit();
}

// ... bestehender Code für andere POST-Anfragen ...
// Diese Kommentarzeile dient als Platzhalter für zukünftige POST-Anfragen
?>
