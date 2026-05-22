<?php
session_start();
include 'config.php';

// Supported languages
$supported_languages = ['de', 'en'];

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = in_array($_GET['lang'], $supported_languages, true) ? $_GET['lang'] : ($_SESSION['lang'] ?? 'de');
    setcookie('lang', $_SESSION['lang'], time() + 31536000, '/', '', false, true);
} elseif (!isset($_SESSION['lang'])) {
    $cookieLang = $_COOKIE['lang'] ?? '';
    $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'de', 0, 2);
    $_SESSION['lang'] = in_array($cookieLang, $supported_languages, true)
        ? $cookieLang
        : (in_array($browserLang, $supported_languages, true) ? $browserLang : 'de');
}

$lang = $_SESSION['lang'];
require_once "languages/$lang.php";

$error = '';
$successMessage = '';

// Generate a CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['error'])) {
    if ($_GET['error'] == 'existinguser') {
        $error = ERROR_EXISTING_USER;
    } else {
        $error = htmlspecialchars($_GET['error']);
    }
}

if (isset($_GET['success'])) {
    $successMessage = htmlspecialchars($_GET['success']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = ERROR_INVALID_CSRF;
    } else {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);

        if (!empty($username) && !empty($password)) {
            // LDAP-Verbindungsdetails aus der Datenbank abrufen
            $stmt = $conn->prepare("SELECT * FROM ldap_settings ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $ldapSettings = $stmt->fetch(PDO::FETCH_OBJ);

            if ($ldapSettings) {
                $ldapHost = $ldapSettings->ldap_host;
                $ldapPort = $ldapSettings->ldap_port;
                $ldapUser = $ldapSettings->ldap_user;
                $ldapPass = $ldapSettings->ldap_pass;
                $ldapBaseDN = $ldapSettings->ldap_base_dn;

                // Check LDAP first
                $ldapConn = ldap_connect($ldapHost, $ldapPort);

                if ($ldapConn) {
                    ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
                    $ldapRdn = "uid=$username,$ldapBaseDN";

                    if (@ldap_bind($ldapConn, $ldapRdn, $password)) {
                        // User authenticated via LDAP
                        // Fetch user details from LDAP and proceed
                        $search = ldap_search($ldapConn, $ldapBaseDN, "(uid=$username)");
                        $entries = ldap_get_entries($ldapConn, $search);

                        if ($entries["count"] > 0) {
                            $ldapUser = $entries[0];
                            $email = $ldapUser["mail"][0];

                            // Check if user exists in local database
                            $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
                            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                            $stmt->execute();
                            $user = $stmt->fetch(PDO::FETCH_OBJ);

                            if (!$user) {
                                // If user doesn't exist locally, insert the user
                                $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (:username, :password, :email, 'user')");
                                $hashedPassword = password_hash($password, PASSWORD_DEFAULT); // Store a hashed password
                                $stmt->bindParam(':username', $username);
                                $stmt->bindParam(':password', $hashedPassword);
                                $stmt->bindParam(':email', $email);
                                $stmt->execute();

                                // Fetch the newly inserted user
                                $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
                                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                                $stmt->execute();
                                $user = $stmt->fetch(PDO::FETCH_OBJ);
                            }

                            // Regenerate session ID to prevent session fixation
                            session_regenerate_id(true);

                            $_SESSION['user_id'] = $user->id;
                            $_SESSION['username'] = $user->username;
                            $_SESSION['role'] = $user->role; // Benutzerrolle speichern
                            $_SESSION['force_password_change'] = (int)($user->force_password_change ?? 0);

                            header("Location: " . ($_SESSION['force_password_change'] ? 'settings.php' : 'index.php'));
                            exit();
                        }
                    }
                    ldap_close($ldapConn);
                }
            }

            // If LDAP authentication fails, check local database
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_OBJ);

            if ($user && password_verify($password, $user->password)) {
                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user->id;
                $_SESSION['username'] = $user->username;
                $_SESSION['role'] = $user->role; // Benutzerrolle speichern
                $_SESSION['force_password_change'] = (int)($user->force_password_change ?? 0);

                header("Location: " . ($_SESSION['force_password_change'] ? 'settings.php' : 'index.php'));
                exit();
            } else {
                $error = ERROR_INVALID_CREDENTIALS;
            }
        } else {
            $error = ERROR_ALL_FIELDS_REQUIRED;
        }
    }
}

// Check if user registration is allowed
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
$stmt->execute();
$totalUsers = $stmt->fetch(PDO::FETCH_OBJ)->count;
$showPrivacyLink = getAppSetting('show_privacy_link', '1') === '1';
$showImprintLink = getAppSetting('show_imprint_link', '1') === '1';
$theme_mode = $_COOKIE['theme'] ?? 'light';
$theme_mode = in_array($theme_mode, ['light', 'dark', 'system'], true) ? $theme_mode : 'light';
$resolved_theme = $theme_mode === 'system' ? 'light' : $theme_mode;

?>

<!DOCTYPE html>
<html lang="<?= $lang ?>" data-theme="<?= htmlspecialchars($resolved_theme) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= LOGIN_TITLE ?></title>
    <link rel="icon" href="assets/timepoint_icon.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.9.4/dist/full.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
        body {
            font-family: 'Poppins', sans-serif;
        }

        [data-theme="light"] body {
            background: linear-gradient(to right, #dbeafe, #eff6ff);
        }

        [data-theme="dark"] body {
            background: hsl(var(--b2));
        }
    </style>
</head>

<body class="bg-base-200 text-base-content flex justify-center items-center min-h-screen p-4 transition-colors duration-300">
    <div class="absolute right-4 top-4 flex items-center gap-2">
        <div class="join" aria-label="<?= LANGUAGE_SELECTION ?>">
            <a href="?lang=de" class="btn btn-sm join-item <?= $lang === 'de' ? 'btn-primary' : 'btn-ghost' ?>">DE</a>
            <a href="?lang=en" class="btn btn-sm join-item <?= $lang === 'en' ? 'btn-primary' : 'btn-ghost' ?>">EN</a>
        </div>
        <button type="button" id="loginThemeToggle" class="btn btn-circle btn-ghost" aria-label="Darkmode umschalten" title="Darkmode umschalten">
            <i id="loginThemeIcon" class="fa-solid fa-moon"></i>
        </button>
    </div>

    <div class="card w-full max-w-md bg-base-100 text-base-content shadow-2xl">
        <div class="card-body">
            <div class="flex flex-col items-center mb-6">
                <img src="assets/timepoint_icon.png" alt="TimePoint Logo" class="w-20 h-20 mb-2" />
                <h2 class="card-title text-2xl font-semibold"><?= LOGIN_TITLE ?></h2>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error shadow-lg mb-4">
                    <div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span><?= $error ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($successMessage): ?>
                <div class="alert alert-success shadow-lg mb-4">
                    <div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span><?= $successMessage ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-control">
                    <label class="label">
                        <span class="label-text"><?= USERNAME_LABEL ?></span>
                    </label>
                    <input type="text" name="username" placeholder="<?= LOGIN_USERNAME_PLACEHOLDER ?>" class="input input-bordered w-full bg-base-200 focus:bg-base-100 transition-colors duration-300" required />
                </div>
                
                <div class="form-control">
                    <label class="label">
                        <span class="label-text"><?= PASSWORD_LABEL ?></span>
                    </label>
                    <input type="password" name="password" placeholder="<?= LOGIN_PASSWORD_PLACEHOLDER ?>" class="input input-bordered w-full bg-base-200 focus:bg-base-100 transition-colors duration-300" required />
                </div>
                
                <div class="form-control mt-6">
                    <button type="submit" class="btn btn-primary w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 ease-in-out transform hover:-translate-y-1 hover:shadow-lg">
                        <?= LOGIN_BUTTON ?>
                    </button>
                </div>
            </form>

            <div class="text-center mt-3">
                <a href="forgot_password.php" class="link link-primary text-sm hover:underline"><?= LOGIN_FORGOT_PASSWORD ?></a>
            </div>
            
            <?php if ($showImprintLink || $showPrivacyLink) : ?>
                <div class="text-center mt-3">
                    <?php if ($showImprintLink) : ?>
                        <button type="button" class="link link-primary text-sm hover:underline" onclick="document.getElementById('imprintModal').showModal()">
                            <?= NAV_IMPRINT ?>
                        </button>
                    <?php endif; ?>
                    <?php if ($showImprintLink && $showPrivacyLink) : ?>
                        <span class="opacity-40 mx-2">|</span>
                    <?php endif; ?>
                    <?php if ($showPrivacyLink) : ?>
                        <button type="button" class="link link-primary text-sm hover:underline" onclick="document.getElementById('privacyModal').showModal()">
                            <?= NAV_PRIVACY ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="text-center pb-6">
            <p class="text-xs opacity-70"><?= FOOTER_TEXT ?></p>
        </div>
    </div>
    <?php if ($showImprintLink) : ?>
    <dialog id="imprintModal" class="modal">
        <div class="modal-box max-w-3xl">
            <h3 class="font-bold text-lg mb-4"><?= NAV_IMPRINT ?></h3>
            <div class="space-y-4 text-sm max-h-[65vh] overflow-y-auto">
                <?php renderLegalPageContent('imprint'); ?>
                <a href="imprint.php" class="link link-primary"><?= LEGAL_OPEN_IMPRINT_PAGE ?></a>
            </div>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn"><?= BUTTON_CLOSE ?></button>
                </form>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button><?= BUTTON_CLOSE ?></button>
        </form>
    </dialog>
    <?php endif; ?>
    <?php if ($showPrivacyLink) : ?>
    <dialog id="privacyModal" class="modal">
        <div class="modal-box max-w-3xl">
            <h3 class="font-bold text-lg mb-4"><?= NAV_PRIVACY ?></h3>
            <div class="space-y-4 text-sm max-h-[65vh] overflow-y-auto">
                <?php renderLegalPageContent('privacy'); ?>
                <a href="privacy.php" class="link link-primary"><?= LEGAL_OPEN_PRIVACY_PAGE ?></a>
            </div>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn"><?= BUTTON_CLOSE ?></button>
                </form>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button><?= BUTTON_CLOSE ?></button>
        </form>
    </dialog>
    <?php endif; ?>
    <script>
        const loginThemeToggle = document.getElementById('loginThemeToggle');
        const loginThemeIcon = document.getElementById('loginThemeIcon');
        const systemThemeQuery = window.matchMedia('(prefers-color-scheme: dark)');

        function resolveLoginTheme(mode) {
            return mode === 'system' ? (systemThemeQuery.matches ? 'dark' : 'light') : mode;
        }

        function setLoginTheme(mode) {
            const resolvedTheme = resolveLoginTheme(mode);
            document.documentElement.setAttribute('data-theme', resolvedTheme);
            document.documentElement.classList.toggle('dark', resolvedTheme === 'dark');
            localStorage.setItem('theme', mode);
            document.cookie = `theme=${mode}; path=/; max-age=31536000; SameSite=Lax`;

            if (loginThemeIcon) {
                loginThemeIcon.className = resolvedTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
            }
        }

        setLoginTheme(localStorage.getItem('theme') || '<?= htmlspecialchars($theme_mode) ?>');

        if (loginThemeToggle) {
            loginThemeToggle.addEventListener('click', function() {
                const currentTheme = localStorage.getItem('theme') || document.documentElement.getAttribute('data-theme') || 'light';
                setLoginTheme(resolveLoginTheme(currentTheme) === 'dark' ? 'light' : 'dark');
            });
        }

        systemThemeQuery.addEventListener('change', function() {
            if ((localStorage.getItem('theme') || '<?= htmlspecialchars($theme_mode) ?>') === 'system') {
                setLoginTheme('system');
            }
        });
    </script>
</body>

</html>
