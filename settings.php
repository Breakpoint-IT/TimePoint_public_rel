<?php
session_start();
include 'config.php';

// Überprüfen, ob der Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? null;
$error = '';
$successMessage = '';
$showSuccessModal = false;

// Sprachdateien laden
$lang = $_SESSION['lang'] ?? 'de';
$langFile = "languages/$lang.php";
if (file_exists($langFile)) {
    require_once $langFile;
} else {
    die("Sprachdatei nicht gefunden!");
}

// Benutzerinformationen laden
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$userInfo['automatic_pause_deduction'] = $userInfo['automatic_pause_deduction'] ?? 1;
$userInfo['pause_duration'] = $userInfo['pause_duration'] ?? 30;
$userInfo['vacation_days_per_year'] = $userInfo['vacation_days_per_year'] ?? 30;
$userInfo['force_password_change'] = $userInfo['force_password_change'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPassword, $userInfo['password'])) {
            $error = 'Das aktuelle Passwort ist nicht korrekt.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Die neuen Passwörter stimmen nicht überein.';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?");
            $stmt->execute([$hashedPassword, $user_id]);
            $userInfo['password'] = $hashedPassword;
            $userInfo['force_password_change'] = 0;
            $_SESSION['force_password_change'] = 0;
            $successMessage = 'Passwort erfolgreich geändert!';
            $showSuccessModal = true;
        }
    } elseif (isset($_POST['lang'])) {
        $lang = $_POST['lang'];
        $_SESSION['lang'] = $lang;
        // Lade die neue Sprachdatei sofort
        $langFile = "languages/$lang.php";
        if (file_exists($langFile)) {
            require_once $langFile;
        }
    }
    if (($_POST['action'] ?? '') !== 'change_password' && isset($_POST['regelarbeitszeit'])) {
        $regelarbeitszeit = floatval($_POST['regelarbeitszeit']);
        $updateSql = "UPDATE users SET regelarbeitszeit = :regelarbeitszeit WHERE id = :id";
        $stmt = $conn->prepare($updateSql);
        $stmt->execute([':regelarbeitszeit' => $regelarbeitszeit, ':id' => $user_id]);
        $userInfo['regelarbeitszeit'] = $regelarbeitszeit;
    }
    if (($_POST['action'] ?? '') !== 'change_password' && isset($_POST['pause_settings_submitted'])) {
        $automaticPauseDeduction = isset($_POST['automatic_pause_deduction']) ? 1 : 0;
        $pauseDuration = max(0, (int)($_POST['pause_duration'] ?? 0));
        $vacationDaysPerYear = max(0, (int)($_POST['vacation_days_per_year'] ?? 0));
        $updateSql = "UPDATE users SET automatic_pause_deduction = :automatic_pause_deduction, pause_duration = :pause_duration, vacation_days_per_year = :vacation_days_per_year WHERE id = :id";
        $stmt = $conn->prepare($updateSql);
        $stmt->execute([
            ':automatic_pause_deduction' => $automaticPauseDeduction,
            ':pause_duration' => $pauseDuration,
            ':vacation_days_per_year' => $vacationDaysPerYear,
            ':id' => $user_id
        ]);
        $userInfo['automatic_pause_deduction'] = $automaticPauseDeduction;
        $userInfo['pause_duration'] = $pauseDuration;
        $userInfo['vacation_days_per_year'] = $vacationDaysPerYear;
    }
    if (($_POST['action'] ?? '') !== 'change_password' && isset($_POST['ueberstunden']) && $user_role === 'admin') {
        $ueberstunden = floatval($_POST['ueberstunden']);
        $updateSql = "UPDATE users SET ueberstunden = :ueberstunden WHERE id = :id";
        $stmt = $conn->prepare($updateSql);
        $stmt->execute([':ueberstunden' => $ueberstunden, ':id' => $user_id]);
        $userInfo['ueberstunden'] = $ueberstunden;
    }
    if (($_POST['action'] ?? '') !== 'change_password' && isset($_POST['theme_mode'])) {
        $theme_mode = $_POST['theme_mode'];
        $_SESSION['theme_mode'] = $theme_mode;
    }
    if (($_POST['action'] ?? '') !== 'change_password') {
        $successMessage = 'Einstellungen erfolgreich aktualisiert!';
        $showSuccessModal = true;
    }
}

$theme_mode = $_SESSION['theme_mode'] ?? 'light';

// Check for import messages
if (isset($_SESSION['import_success'])) {
    $successMessage = $_SESSION['import_success'];
    unset($_SESSION['import_success']);
    $showSuccessModal = true;
}

if (isset($_SESSION['import_error'])) {
    $error = $_SESSION['import_error'];
    unset($_SESSION['import_error']);
}

include 'header.php';
?>

<div class="min-h-screen bg-base-200">
    <div class="container mx-auto px-4 py-8">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
            <h2 class="card-title text-3xl mb-6 flex items-center">
                <img src="<?= $timepoint_icon ?>" alt="TimePoint Logo" class="w-12 h-12 mr-4">
                <?= SETTINGS_TITLE ?>
            </h2>

            <?php if ($error) : ?>
                <div class="alert alert-error shadow-lg mb-4">
                    <div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span><?= $error ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ((int)$userInfo['force_password_change'] === 1) : ?>
                <div class="alert alert-warning shadow-lg mb-6">
                    <div>
                        <i class="fas fa-key"></i>
                        <span>Bitte ändern Sie Ihr Passwort, bevor Sie TimePoint weiter nutzen.</span>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="form-control">
                        <label class="label" for="lang">
                            <span class="label-text"><i class="fas fa-language mr-2"></i><?= SETTINGS_LANGUAGE ?></span>
                        </label>
                        <select name="lang" id="lang" class="select select-bordered w-full">
                            <option value="de" <?= $lang == 'de' ? 'selected' : '' ?>>Deutsch</option>
                            <option value="en" <?= $lang == 'en' ? 'selected' : '' ?>>English</option>
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label" for="regelarbeitszeit">
                            <span class="label-text"><i class="fas fa-clock mr-2"></i><?= SETTINGS_REGULAR_WORKING_HOURS ?></span>
                        </label>
                        <input type="number" step="0.1" id="regelarbeitszeit" name="regelarbeitszeit" value="<?= $userInfo['regelarbeitszeit'] ?>" min="0" max="24" class="input input-bordered w-full">
                    </div>

                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3" for="automatic_pause_deduction">
                            <input type="checkbox" id="automatic_pause_deduction" name="automatic_pause_deduction" class="toggle toggle-primary" <?= (int)$userInfo['automatic_pause_deduction'] === 1 ? 'checked' : '' ?>>
                            <span class="label-text"><i class="fas fa-mug-hot mr-2"></i>Automatischer Pausenabzug</span>
                        </label>
                        <input type="hidden" name="pause_settings_submitted" value="1">
                    </div>

                    <div class="form-control">
                        <label class="label" for="pause_duration">
                            <span class="label-text"><i class="fas fa-stopwatch mr-2"></i>Pausendauer in Minuten</span>
                        </label>
                        <input type="number" step="1" id="pause_duration" name="pause_duration" value="<?= htmlspecialchars($userInfo['pause_duration']) ?>" min="0" max="240" class="input input-bordered w-full">
                    </div>

                    <div class="form-control">
                        <label class="label" for="vacation_days_per_year">
                            <span class="label-text"><i class="fas fa-umbrella-beach mr-2"></i>Urlaubstage pro Jahr</span>
                        </label>
                        <input type="number" step="1" id="vacation_days_per_year" name="vacation_days_per_year" value="<?= htmlspecialchars($userInfo['vacation_days_per_year']) ?>" min="0" max="366" class="input input-bordered w-full">
                    </div>

                    <?php if ($user_role === 'admin') : ?>
                        <div class="form-control">
                            <label class="label" for="ueberstunden">
                                <span class="label-text"><i class="fas fa-hourglass mr-2"></i><?= SETTINGS_OVERTIME ?></span>
                            </label>
                            <input type="number" step="0.1" id="ueberstunden" name="ueberstunden" value="<?= $userInfo['ueberstunden'] ?>" min="0" class="input input-bordered w-full">
                        </div>
                    <?php endif; ?>

                    <div class="form-control">
                        <label class="label" for="theme_mode">
                            <span class="label-text"><i class="fas fa-adjust mr-2"></i><?= NAV_DARK_MODE ?></span>
                        </label>
                        <select name="theme_mode" id="theme_mode" class="select select-bordered w-full">
                            <option value="light" <?= $theme_mode == 'light' ? 'selected' : '' ?>>Hell</option>
                            <option value="dark" <?= $theme_mode == 'dark' ? 'selected' : '' ?>>Dunkel</option>
                            <option value="system" <?= $theme_mode == 'system' ? 'selected' : '' ?>>System</option>
                        </select>
                    </div>
                </div>

                <div class="mt-6">
                    <button type="submit" class="btn btn-primary w-full md:w-auto"><i class="fas fa-save mr-2"></i><?= BUTTON_SAVE_CHANGES ?></button>
                </div>
            </form>

            <div class="divider my-8">Passwort</div>
            <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <input type="hidden" name="action" value="change_password">
                <div class="form-control">
                    <label class="label" for="current_password">
                        <span class="label-text"><i class="fas fa-lock mr-2"></i>Aktuelles Passwort</span>
                    </label>
                    <input type="password" id="current_password" name="current_password" class="input input-bordered w-full" required>
                </div>
                <div class="form-control">
                    <label class="label" for="new_password">
                        <span class="label-text"><i class="fas fa-key mr-2"></i>Neues Passwort</span>
                    </label>
                    <input type="password" id="new_password" name="new_password" class="input input-bordered w-full" minlength="8" required>
                </div>
                <div class="form-control">
                    <label class="label" for="confirm_password">
                        <span class="label-text"><i class="fas fa-check mr-2"></i>Neues Passwort wiederholen</span>
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" class="input input-bordered w-full" minlength="8" required>
                </div>
                <div class="md:col-span-3">
                    <button type="submit" class="btn btn-primary w-full md:w-auto">
                        <i class="fas fa-save mr-2"></i>Passwort ändern
                    </button>
                </div>
            </form>

            </div>
        </div>
    </div>
</div>

<?php if ($successMessage && $showSuccessModal) : ?>
    <div id="successModal" class="modal modal-open">
        <div class="modal-box">
            <h3 class="font-bold text-lg">Erfolg!</h3>
            <p class="py-4"><?= $successMessage ?></p>
            <div class="modal-action">
                <button onclick="closeModal()" class="btn btn-primary">Schließen</button>
            </div>
        </div>
    </div>

    <script>
        function closeModal() {
            document.getElementById('successModal').classList.remove('modal-open');
        }
    </script>
<?php endif; ?>

<script>
    function settingsLegacyThemeDisabled(theme) {
        return theme;
    }

    // Theme beim Laden der Seite setzen
    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        settingsLegacyThemeDisabled(savedTheme);
    });

    // Theme-Wechsel überwachen
    const themeSelect = document.getElementById('theme_mode');
    if (themeSelect) {
        themeSelect.addEventListener('change', function() {
            settingsLegacyThemeDisabled(this.value);
        });
    }
</script>
