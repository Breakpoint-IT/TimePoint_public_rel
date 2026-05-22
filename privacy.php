<?php
require_once 'config.php';
$lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'de');
$lang = in_array($lang, ['de', 'en'], true) ? $lang : 'de';
require_once __DIR__ . "/languages/$lang.php";
if (getAppSetting('show_privacy_link', '1') !== '1') {
    http_response_code(404);
    exit(LEGAL_PRIVACY_DISABLED);
}
include 'header.php';
?>

<div class="min-h-screen bg-base-200">
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
                <h1 class="card-title text-3xl mb-6">
                    <i class="fa-solid fa-shield-halved mr-2"></i>
                    <?= NAV_PRIVACY ?>
                </h1>

                <div class="alert alert-warning mb-6">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>
                        <?= LEGAL_PRIVACY_PLACEHOLDER_NOTICE ?>
                    </span>
                </div>

                <?php renderLegalPageContent('privacy'); ?>
            </div>
        </div>
    </div>
</div>
</div>
