<?php
session_start();
include 'config.php';

$error = '';
$successMessage = '';

// Generate a CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user registration is allowed
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
$stmt->execute();
$totalUsers = $stmt->fetch(PDO::FETCH_OBJ)->count;
$showPrivacyLink = getAppSetting('show_privacy_link', '1') === '1';
$showImprintLink = getAppSetting('show_imprint_link', '1') === '1';

if ($totalUsers > 0) {
    header("Location: login.php?error=existinguser");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Ungültiges CSRF-Token.";
    } else {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $email = trim($_POST['email']);

        if (!empty($username) && !empty($password) && !empty($email)) {
            // Check if username or email already exists
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = :username OR email = :email");
            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            $userCount = $stmt->fetch(PDO::FETCH_OBJ)->count;

            if ($userCount > 0) {
                $error = "Benutzername oder E-Mail ist bereits vergeben.";
            } else {
                $role = $totalUsers == 0 ? 'admin' : 'user';

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                try {
                    // Prepare and execute the statement to insert the new user
                    $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $hashedPassword, $email, $role]);

                    // Set success message
                    $successMessage = "User $username erfolgreich angelegt";
                } catch (PDOException $e) {
                    $error = "Fehler bei der Registrierung: " . $e->getMessage();
                }
            }
        } else {
            $error = "Bitte alle Felder ausfüllen!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <!-- Meta tags and title -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrieren - TimePoint</title>

    <!-- Favicon and external stylesheets -->
    <link rel="icon" href="assets/timepoint_icon" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="./assets/css/main.css">

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom pl-3">
        <a class="navbar-brand" href="#">
            <img class="pl-3" src="assets/timepoint_icon.png" alt="Time Tracking" height="50">
        </a>
    </nav>

    <!-- Main content -->
    <div class="container mt-5 p-5">
        <h2>Registrieren</h2>
        <form method="post" class="mt-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="mb-3">
                <label for="username" class="form-label">Benutzername</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Passwort</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">E-Mail</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <button type="submit" class="btn btn-primary">Registrieren</button>
            <?php if ($showImprintLink) : ?>
                <button type="button" class="btn btn-link" data-bs-toggle="modal" data-bs-target="#imprintModal">
                    Impressum
                </button>
            <?php endif; ?>
            <?php if ($showPrivacyLink) : ?>
                <button type="button" class="btn btn-link" data-bs-toggle="modal" data-bs-target="#privacyModal">
                    Datenschutz
                </button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($showImprintLink) : ?>
    <div class="modal fade" id="imprintModal" tabindex="-1" aria-labelledby="imprintModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imprintModalLabel">Impressum</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6>Angaben gemäß § 5 DDG</h6>
                    <p>
                        [Unternehmen / Vorname Nachname]<br>
                        [Straße Hausnummer]<br>
                        [PLZ Ort]<br>
                        [Deutschland]
                    </p>

                    <h6>Kontakt</h6>
                    <p>
                        Telefon: [Telefonnummer]<br>
                        E-Mail: [E-Mail-Adresse]
                    </p>

                    <h6>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h6>
                    <p>[Vorname Nachname], [Straße Hausnummer], [PLZ Ort]</p>

                    <h6>Verbraucherstreitbeilegung</h6>
                    <p>Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>

                    <a href="imprint.php">Impressum als eigene Seite öffnen</a>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showPrivacyLink) : ?>
    <div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="privacyModalLabel">Datenschutz</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php include __DIR__ . '/partials/privacy_content.php'; ?>
                    <a href="privacy.php">Datenschutz als eigene Seite öffnen</a>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal for Success -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="successModalLabel">Erfolg</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= $successMessage ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Error -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-labelledby="errorModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="errorModalLabel">Fehler</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= $error ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer mt-auto py-3">
        <div class="container">
            <span class="text-muted">© 2026 TimePoint - Zeiterfassung</span>
        </div>
    </footer>

    <!-- Show modals if there are messages and redirect after close -->
    <script>
        $(document).ready(function() {
            <?php if ($successMessage) : ?>
                var successModal = new bootstrap.Modal(document.getElementById('successModal'));
                successModal.show();
                $('#successModal').on('hidden.bs.modal', function () {
                    window.location.href = 'login.php';
                });
            <?php endif; ?>

            <?php if ($error) : ?>
                var errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
                errorModal.show();
            <?php endif; ?>
        });
    </script>
</body>

</html>
