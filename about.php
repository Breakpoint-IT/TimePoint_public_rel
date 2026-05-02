<?php
include 'header.php';

$version = defined('TIMEPOINT_VERSION') ? TIMEPOINT_VERSION : '';
$changelogUrl = defined('TIMEPOINT_CHANGELOG_URL') ? TIMEPOINT_CHANGELOG_URL : '';
?>

<div class="min-h-screen bg-base-200">
    <div class="container mx-auto px-4 py-8">
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <section class="card bg-base-100 shadow-xl xl:col-span-1">
                <div class="card-body">
                    <h1 class="card-title text-3xl mb-4">
                        <i class="fas fa-info-circle text-primary"></i>
                        Über TimePoint
                    </h1>
                    <div class="badge badge-primary badge-lg mb-4">Version <?= htmlspecialchars($version) ?></div>
                    <p>
                        TimePoint ist eine Zeiterfassung für Arbeitszeiten, Pausen, Abwesenheiten,
                        Auswertungen und Exporte.
                    </p>

                    <div class="divider"></div>

                    <h2 class="text-xl font-semibold">Entwickler Informationen</h2>
                    <div class="space-y-2">
                        <p><strong>Entwickler:</strong> Marek Tonino</p>
                        <p><strong>Projekt:</strong> TimePoint Zeiterfassung</p>
                        <p><strong>Technik:</strong> PHP, SQLite, Tailwind CSS, DaisyUI</p>
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 shadow-xl xl:col-span-2">
                <div class="card-body">
                    <h2 class="card-title text-2xl mb-4">
                        <i class="fas fa-list-check text-primary"></i>
                        Änderungslog
                    </h2>

                    <?php if ($changelogUrl && !str_contains($changelogUrl, 'example.com')) : ?>
                        <iframe
                            src="<?= htmlspecialchars($changelogUrl) ?>"
                            title="TimePoint Änderungslog"
                            class="w-full min-h-[620px] rounded-lg border border-base-300 bg-base-100"
                            loading="lazy"
                            referrerpolicy="no-referrer">
                        </iframe>
                    <?php else : ?>
                        <div class="alert alert-info">
                            <i class="fas fa-link"></i>
                            <span>Bitte die Changelog-URL in <code>config.php</code> unter <code>TIMEPOINT_CHANGELOG_URL</code> eintragen.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
