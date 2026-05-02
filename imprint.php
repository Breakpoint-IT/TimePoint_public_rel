<?php
require_once 'config.php';
if (getAppSetting('show_imprint_link', '1') !== '1') {
    http_response_code(404);
    exit('Impressum ist deaktiviert.');
}
include 'header.php';
?>

<div class="min-h-screen bg-base-200">
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
                <h1 class="card-title text-3xl mb-6">
                    <i class="fa-solid fa-gavel mr-2"></i>
                    Impressum
                </h1>

                <div class="alert alert-warning mb-6">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>
                        Bitte ersetzen Sie die Platzhalter vor der Veröffentlichung durch die tatsächlichen Anbieterangaben.
                    </span>
                </div>

                <?php include __DIR__ . '/partials/imprint_content.php'; ?>
            </div>
        </div>
    </div>
</div>
</div>
