<?php
session_start();

$supportedLanguages = ['de', 'en'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLanguages, true)) {
    $_SESSION['lang'] = $_GET['lang'];
    setcookie('lang', $_GET['lang'], time() + 31536000, '/', '', false, true);
}
$lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'de', 0, 2));
$lang = in_array($lang, $supportedLanguages, true) ? $lang : 'de';
$_SESSION['lang'] = $lang;
require_once __DIR__ . "/languages/$lang.php";

$configPath = getenv('TIMEPOINT_CONFIG_PATH') ?: __DIR__ . '/config.local.php';

if (file_exists($configPath)) {
    header('Location: login.php');
    exit();
}

$error = '';
$successMessage = '';
$connectionTested = false;
$showRegistration = false;
$configWritten = false;

function setupEnv(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string)$_SERVER[$key];
    }

    return $default;
}

function setupDefaultDbValues(): array
{
    return [
        'driver' => setupEnv('TIMEPOINT_DB_DRIVER', setupEnv('TIMEPOINT_SETUP_DEFAULT_DRIVER', 'pgsql')),
        'host' => setupEnv('TIMEPOINT_DB_HOST', setupEnv('TIMEPOINT_SETUP_DEFAULT_HOST', 'localhost')),
        'port' => setupEnv('TIMEPOINT_DB_PORT', setupEnv('TIMEPOINT_SETUP_DEFAULT_PORT', '5432')),
        'database' => setupEnv('TIMEPOINT_DB_NAME', setupEnv('TIMEPOINT_SETUP_DEFAULT_DATABASE', 'timepoint')),
        'username' => setupEnv('TIMEPOINT_DB_USER', setupEnv('TIMEPOINT_SETUP_DEFAULT_USERNAME', '')),
        'password' => setupEnv('TIMEPOINT_DB_PASSWORD', setupEnv('TIMEPOINT_SETUP_DEFAULT_PASSWORD', '')),
    ];
}

function setupPostedDbValues(): array
{
    return [
        'driver' => $_POST['driver'] ?? 'pgsql',
        'host' => trim((string)($_POST['host'] ?? 'localhost')),
        'port' => trim((string)($_POST['port'] ?? '')),
        'database' => trim((string)($_POST['database'] ?? '')),
        'username' => trim((string)($_POST['username'] ?? '')),
        'password' => (string)($_POST['password'] ?? ''),
    ];
}

function setupValidateDbValues(array $values): void
{
    if (!in_array($values['driver'], ['pgsql', 'mysql'], true)) {
        throw new RuntimeException(SETUP_SELECT_DB);
    }

    if ($values['host'] === '' || $values['port'] === '' || $values['database'] === '' || $values['username'] === '') {
        throw new RuntimeException(SETUP_REQUIRED_DB_FIELDS);
    }
}

function setupBuildDsn(array $values): string
{
    $charset = $values['driver'] === 'mysql' ? ';charset=utf8mb4' : '';

    return sprintf(
        '%s:host=%s;port=%d;dbname=%s%s',
        $values['driver'],
        $values['host'],
        (int)$values['port'],
        $values['database'],
        $charset
    );
}

function setupTestConnection(array $values): void
{
    setupValidateDbValues($values);

    $testConn = new PDO(setupBuildDsn($values), $values['username'], $values['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $testConn = null;
}

function setupWriteConfig(string $configPath, array $values): void
{
    $config = "<?php\n\nreturn " . var_export([
        'driver' => $values['driver'],
        'host' => $values['host'],
        'port' => (int)$values['port'],
        'database' => $values['database'],
        'username' => $values['username'],
        'password' => $values['password'],
    ], true) . ";\n";

    $configDir = dirname($configPath);
    if (!is_dir($configDir) && !mkdir($configDir, 0750, true)) {
        throw new RuntimeException(SETUP_CONFIG_DIR_ERROR);
    }

    if (file_put_contents($configPath, $config, LOCK_EX) === false) {
        throw new RuntimeException(SETUP_CONFIG_WRITE_ERROR);
    }
}

function selectedDriver(string $driver, string $current): string
{
    return $driver === $current ? 'checked' : '';
}

function setupDbLabel(string $driver): string
{
    return $driver === 'mysql' ? 'MariaDB' : 'PostgreSQL';
}

function setupRequireTestedDb(): array
{
    if (empty($_SESSION['setup_db_values']) || !is_array($_SESSION['setup_db_values'])) {
        throw new RuntimeException(SETUP_TEST_DB_FIRST);
    }

    return $_SESSION['setup_db_values'];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$values = $_SESSION['setup_db_values'] ?? setupDefaultDbValues();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new RuntimeException(ERROR_INVALID_CSRF);
        }

        $action = $_POST['setup_action'] ?? '';

        if ($action === 'test_connection') {
            $values = setupPostedDbValues();
            setupTestConnection($values);
            $_SESSION['setup_db_values'] = $values;
            $connectionTested = true;
            $successMessage = SETUP_DB_TESTED_SUCCESS;
        } elseif ($action === 'reset_connection') {
            $values = setupRequireTestedDb();
            unset($_SESSION['setup_db_values']);
            $successMessage = SETUP_CONNECTION_RELEASED;
        } elseif ($action === 'show_registration') {
            $values = setupRequireTestedDb();
            setupTestConnection($values);
            $showRegistration = true;
        } elseif ($action === 'complete_setup') {
            $values = setupRequireTestedDb();
            setupTestConnection($values);

            $adminUsername = trim((string)($_POST['admin_username'] ?? ''));
            $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
            $adminPassword = (string)($_POST['admin_password'] ?? '');
            $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

            if ($adminUsername === '' || $adminEmail === '' || $adminPassword === '') {
                throw new RuntimeException(SETUP_REGISTRATION_REQUIRED);
            }

            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(ERROR_INVALID_EMAIL);
            }

            if (strlen($adminPassword) < 8) {
                throw new RuntimeException(ERROR_PASSWORD_MIN_LENGTH);
            }

            if ($adminPassword !== $adminPasswordConfirm) {
                throw new RuntimeException(PASSWORD_CONFIRM_MISMATCH);
            }

            setupWriteConfig($configPath, $values);
            $configWritten = true;
            require __DIR__ . '/config.php';

            $userCount = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($userCount > 0) {
                throw new RuntimeException(SETUP_EXISTING_USERS_ERROR);
            }

            $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'admin')");
            $stmt->execute([
                $adminUsername,
                password_hash($adminPassword, PASSWORD_DEFAULT),
                $adminEmail,
            ]);

            unset($_SESSION['setup_db_values']);
            header('Location: login.php?success=' . urlencode(SETUP_COMPLETE_LOGIN));
            exit();
        }
    } catch (Throwable $e) {
        if (($action ?? '') === 'complete_setup' && $configWritten && file_exists($configPath)) {
            @unlink($configPath);
        }
        if (($action ?? '') === 'complete_setup') {
            $showRegistration = true;
        }
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SETUP_PAGE_TITLE ?></title>
    <link rel="icon" href="assets/timepoint_icon.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.9.4/dist/full.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            background: hsl(var(--b2));
        }
    </style>
</head>
<body class="bg-base-200 text-base-content">
    <main class="container mx-auto px-4 py-8">
        <div class="flex justify-end mb-4">
            <div class="join" aria-label="<?= LANGUAGE_SELECTION ?>">
                <a href="?lang=de" class="btn btn-sm join-item <?= $lang === 'de' ? 'btn-primary' : 'btn-ghost' ?>">DE</a>
                <a href="?lang=en" class="btn btn-sm join-item <?= $lang === 'en' ? 'btn-primary' : 'btn-ghost' ?>">EN</a>
            </div>
        </div>
        <div class="mb-8 text-center">
            <img src="assets/timepoint_icon.png" alt="TimePoint Logo" class="w-20 h-20 mx-auto mb-4">
            <h1 class="text-4xl font-bold"><?= SETUP_PAGE_TITLE ?></h1>
            <p class="mt-2 opacity-70"><?= SETUP_INTRO ?></p>
        </div>

        <?php if ($error) : ?>
            <div class="alert alert-error shadow-lg max-w-4xl mx-auto mb-6">
                <i class="fas fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($successMessage) : ?>
            <div class="alert alert-success shadow-lg max-w-4xl mx-auto mb-6">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($successMessage) ?></span>
            </div>
        <?php endif; ?>

        <div class="max-w-4xl mx-auto mb-8">
            <ul class="steps w-full">
                <li class="step step-primary"><?= SETUP_STEP_DATABASE ?></li>
                <li class="step <?= ($connectionTested || $showRegistration) ? 'step-primary' : '' ?>"><?= SETUP_STEP_CONNECTION ?></li>
                <li class="step <?= $showRegistration ? 'step-primary' : '' ?>"><?= SETUP_STEP_ADMIN ?></li>
            </ul>
        </div>

        <?php if (!$showRegistration && $connectionTested) : ?>
            <section class="max-w-4xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6">
                <aside class="stat bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-box shadow-lg text-white">
                    <div class="stat-figure opacity-70">
                        <i class="fas fa-plug-circle-check fa-3x"></i>
                    </div>
                    <div class="stat-title text-lg font-semibold opacity-80 text-white"><?= SETUP_CONNECTION_TESTED ?></div>
                    <div class="stat-value text-3xl"><?= htmlspecialchars(setupDbLabel($values['driver'])) ?></div>
                    <div class="stat-desc text-sm font-medium opacity-80 text-white"><?= htmlspecialchars($values['host']) ?>:<?= htmlspecialchars((string)$values['port']) ?></div>
                </aside>

                <div class="card bg-base-100 shadow-xl lg:col-span-2">
                    <div class="card-body">
                        <h2 class="card-title"><?= SETUP_TESTED_DB_CONNECTION ?></h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                            <div><span class="font-semibold">Host:</span> <?= htmlspecialchars($values['host']) ?></div>
                            <div><span class="font-semibold">Port:</span> <?= htmlspecialchars((string)$values['port']) ?></div>
                            <div><span class="font-semibold"><?= SETUP_STEP_DATABASE ?>:</span> <?= htmlspecialchars($values['database']) ?></div>
                            <div><span class="font-semibold"><?= FORM_USERNAME ?>:</span> <?= htmlspecialchars($values['username']) ?></div>
                        </div>
                        <div class="card-actions justify-end mt-6">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="setup_action" value="reset_connection">
                                <button type="submit" class="btn btn-ghost"><?= SETUP_CHANGE_CONNECTION ?></button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="setup_action" value="show_registration">
                                <button type="submit" class="btn btn-primary">
                                    <?= SETUP_CONTINUE_REGISTRATION ?>
                                    <i class="fas fa-arrow-right ml-2"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        <?php elseif (!$showRegistration) : ?>
            <form method="post" class="max-w-4xl mx-auto space-y-8">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="setup_action" value="test_connection">

                <section class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <label class="stat bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-box shadow-lg cursor-pointer text-white">
                        <input type="radio" name="driver" value="pgsql" class="radio radio-primary absolute opacity-0" <?= selectedDriver('pgsql', $values['driver']) ?>>
                        <div class="stat-figure opacity-70">
                            <i class="fas fa-database fa-3x"></i>
                        </div>
                        <div class="stat-title text-lg font-semibold opacity-80 text-white"><?= SETUP_RECOMMENDED ?></div>
                        <div class="stat-value text-3xl">PostgreSQL</div>
                        <div class="stat-desc text-sm font-medium opacity-80 text-white"><?= SETUP_POSTGRES_DESC ?></div>
                    </label>

                    <label class="stat bg-gradient-to-br from-emerald-600 to-emerald-700 rounded-box shadow-lg cursor-pointer text-white">
                        <input type="radio" name="driver" value="mysql" class="radio radio-primary absolute opacity-0" <?= selectedDriver('mysql', $values['driver']) ?>>
                        <div class="stat-figure opacity-70">
                            <i class="fas fa-server fa-3x"></i>
                        </div>
                        <div class="stat-title text-lg font-semibold opacity-80 text-white"><?= SETUP_ALTERNATIVE ?></div>
                        <div class="stat-value text-3xl">MariaDB</div>
                        <div class="stat-desc text-sm font-medium opacity-80 text-white"><?= SETUP_MARIADB_DESC ?></div>
                    </label>
                </section>

                <section class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title text-2xl mb-4">
                            <i class="fas fa-plug mr-2"></i><?= SETUP_DB_CONNECTION ?>
                        </h2>
                        <div class="alert alert-info mb-4">
                            <i class="fas fa-circle-info"></i>
                            <span><?= SETUP_ENV_NOTICE ?></span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="label" for="host"><span class="label-text">Host</span></label>
                                <input id="host" name="host" class="input input-bordered" value="<?= htmlspecialchars($values['host']) ?>" required>
                            </div>
                            <div class="form-control">
                                <label class="label" for="port"><span class="label-text">Port</span></label>
                                <input id="port" name="port" type="number" class="input input-bordered" value="<?= htmlspecialchars((string)$values['port']) ?>" required>
                            </div>
                            <div class="form-control">
                                <label class="label" for="database"><span class="label-text"><?= SETUP_DATABASE_NAME ?></span></label>
                                <input id="database" name="database" class="input input-bordered" value="<?= htmlspecialchars($values['database']) ?>" required>
                            </div>
                            <div class="form-control">
                                <label class="label" for="username"><span class="label-text"><?= FORM_USERNAME ?></span></label>
                                <input id="username" name="username" class="input input-bordered" value="<?= htmlspecialchars($values['username']) ?>" required>
                            </div>
                            <div class="form-control md:col-span-2">
                                <label class="label" for="password"><span class="label-text"><?= FORM_PASSWORD ?></span></label>
                                <input id="password" name="password" type="password" class="input input-bordered" value="<?= htmlspecialchars($values['password']) ?>">
                            </div>
                        </div>
                        <div class="card-actions justify-end mt-6">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-plug-circle-check mr-2"></i><?= SETUP_TEST_CONNECTION ?>
                            </button>
                        </div>
                    </div>
                </section>
            </form>

        <?php else : ?>
            <section class="max-w-4xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6">
                <aside class="stat bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-box shadow-lg text-white">
                    <div class="stat-figure opacity-70">
                        <i class="fas fa-database fa-3x"></i>
                    </div>
                    <div class="stat-title text-lg font-semibold opacity-80 text-white"><?= SETUP_CONNECTION_TESTED ?></div>
                    <div class="stat-value text-3xl"><?= htmlspecialchars(setupDbLabel($values['driver'])) ?></div>
                    <div class="stat-desc text-sm font-medium opacity-80 text-white"><?= htmlspecialchars($values['host']) ?>:<?= htmlspecialchars((string)$values['port']) ?></div>
                </aside>

                <form method="post" class="card bg-base-100 shadow-xl lg:col-span-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="setup_action" value="complete_setup">
                    <div class="card-body">
                        <h2 class="card-title text-2xl mb-4">
                            <i class="fas fa-user-shield mr-2"></i><?= SETUP_CREATE_ADMIN ?>
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="label" for="admin_username"><span class="label-text"><?= FORM_USERNAME ?></span></label>
                                <input id="admin_username" name="admin_username" class="input input-bordered" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" required>
                            </div>
                            <div class="form-control">
                                <label class="label" for="admin_email"><span class="label-text">E-Mail</span></label>
                                <input id="admin_email" name="admin_email" type="email" class="input input-bordered" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                            </div>
                            <div class="form-control">
                                <label class="label" for="admin_password"><span class="label-text"><?= FORM_PASSWORD ?></span></label>
                                <input id="admin_password" name="admin_password" type="password" minlength="8" class="input input-bordered" required>
                            </div>
                            <div class="form-control">
                                <label class="label" for="admin_password_confirm"><span class="label-text"><?= SETUP_CONFIRM_PASSWORD ?></span></label>
                                <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="8" class="input input-bordered" required>
                            </div>
                        </div>
                        <div class="card-actions justify-end mt-6">
                            <a href="setup.php" class="btn btn-ghost"><?= COMMON_BACK ?></a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check mr-2"></i><?= SETUP_COMPLETE ?>
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
    <script>
        const defaults = { pgsql: '5432', mysql: '3306' };
        document.querySelectorAll('input[name="driver"]').forEach((input) => {
            input.addEventListener('change', () => {
                document.getElementById('port').value = defaults[input.value];
            });
        });
    </script>
</body>
</html>
