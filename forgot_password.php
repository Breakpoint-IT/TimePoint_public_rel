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
$themeMode = $_SESSION['theme_mode'] ?? 'system';
$resolvedTheme = $themeMode === 'dark' ? 'dark' : 'light';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = ERROR_INVALID_CSRF;
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $successMessage = FORGOT_PASSWORD_SUCCESS;

        if ($identifier !== '') {
            $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch(PDO::FETCH_OBJ);

            if ($user && filter_var($user->email, FILTER_VALIDATE_EMAIL) && !tpIsDemoAdminUserId($user->id)) {
                try {
                    $reset = createPasswordResetToken((int)$user->id);
                    $resetUrl = buildTimePointUrl('reset_password.php', ['token' => $reset['token']]);
                    sendPasswordResetMail($user, $resetUrl);
                } catch (Throwable $e) {
                    $error = FORGOT_PASSWORD_MAIL_ERROR;
                    $successMessage = '';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" data-theme="<?= htmlspecialchars($resolvedTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= FORGOT_PASSWORD_TITLE ?> - TimePoint</title>
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
                <h1 class="card-title text-2xl"><?= FORGOT_PASSWORD_TITLE ?></h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error mb-4"><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>
            <?php if ($successMessage): ?>
                <div class="alert alert-success mb-4"><span><?= htmlspecialchars($successMessage) ?></span></div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-control">
                    <label class="label" for="identifier">
                        <span class="label-text"><?= FORGOT_PASSWORD_IDENTIFIER ?></span>
                    </label>
                    <input type="text" id="identifier" name="identifier" class="input input-bordered" required>
                </div>
                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-paper-plane mr-2"></i><?= FORGOT_PASSWORD_SEND_LINK ?>
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="login.php" class="link link-primary text-sm"><?= FORGOT_PASSWORD_BACK_TO_LOGIN ?></a>
            </div>
        </div>
    </div>
</body>
</html>
