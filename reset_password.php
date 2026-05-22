<?php
session_start();
include 'config.php';
require_once __DIR__ . '/app/mail/mail_settings.php';

$supportedLanguages = ['de', 'en'];
$lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'de', 0, 2));
$lang = in_array($lang, $supportedLanguages, true) ? $lang : 'de';
$_SESSION['lang'] = $lang;
require_once __DIR__ . "/languages/$lang.php";

$error = '';
$successMessage = '';
$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$reset = findPasswordResetToken($token);
$themeMode = $_SESSION['theme_mode'] ?? 'system';
$resolvedTheme = $themeMode === 'dark' ? 'dark' : 'light';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!$reset) {
    $error = RESET_PASSWORD_INVALID_TOKEN;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = ERROR_INVALID_CSRF;
    } else {
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if (tpIsDemoAdminUserId($reset->user_id)) {
            $error = tpDemoModeMessage();
        } elseif (strlen($password) < 8) {
            $error = PASSWORD_MIN_LENGTH_ERROR;
        } elseif ($password !== $passwordConfirm) {
            $error = PASSWORD_CONFIRM_MISMATCH;
        } else {
            $stmt = $conn->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?");
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $reset->user_id]);
            markPasswordResetTokenUsed((int)$reset->id);
            $successMessage = RESET_PASSWORD_SUCCESS;
            $reset = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" data-theme="<?= htmlspecialchars($resolvedTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= RESET_PASSWORD_TITLE ?> - TimePoint</title>
    <link rel="icon" href="assets/timepoint_icon.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.9.4/dist/full.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="bg-base-200 text-base-content flex justify-center items-center min-h-screen p-4">
    <div class="card w-full max-w-md bg-base-100 shadow-2xl">
        <div class="card-body">
            <div class="flex flex-col items-center mb-4">
                <img src="assets/timepoint_icon.png" alt="TimePoint Logo" class="w-16 h-16 mb-2">
                <h1 class="card-title text-2xl"><?= RESET_PASSWORD_TITLE ?></h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error mb-4"><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>
            <?php if ($successMessage): ?>
                <div class="alert alert-success mb-4"><span><?= htmlspecialchars($successMessage) ?></span></div>
            <?php endif; ?>

            <?php if ($reset): ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="form-control">
                        <label class="label" for="password">
                            <span class="label-text"><?= SETTINGS_NEW_PASSWORD ?></span>
                        </label>
                        <input type="password" id="password" name="password" class="input input-bordered" minlength="8" required>
                    </div>
                    <div class="form-control">
                        <label class="label" for="password_confirm">
                            <span class="label-text"><?= RESET_PASSWORD_CONFIRM ?></span>
                        </label>
                        <input type="password" id="password_confirm" name="password_confirm" class="input input-bordered" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-full">
                        <i class="fas fa-key mr-2"></i><?= RESET_PASSWORD_SAVE ?>
                    </button>
                </form>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="login.php" class="link link-primary text-sm"><?= RESET_PASSWORD_TO_LOGIN ?></a>
            </div>
        </div>
    </div>
</body>
</html>
