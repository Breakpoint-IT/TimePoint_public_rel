<?php
include 'header.php';

// Überprüfen, ob der Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

// Zeiten der unterstellten Benutzer aus der Datenbank abrufen
$zeiten = [];
$ueberstundenListe = [];
$detaillierteDaten = [];
$managedUsers = [];
if ($user_role === 'admin' || $user_role === 'supervisor') {
    if ($user_role === 'admin') {
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE id != ? ORDER BY username");
        $stmt->execute([$user_id]);
        $managedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT z.*, u.username, u.id as user_id, u.regelarbeitszeit, u.ueberstunden as vorherige_ueberstunden, " . tpSqlDate('z.startzeit') . " AS day, " . tpSqlWeek('z.startzeit') . " AS week_number
                                FROM zeiterfassung z
                                JOIN users u ON z.user_id = u.id
                                WHERE z.user_id != ?
                                ORDER BY z.startzeit DESC");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE supervisor_id = ? ORDER BY username");
        $stmt->execute([$user_id]);
        $managedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT z.*, u.username, u.id as user_id, u.regelarbeitszeit, u.ueberstunden as vorherige_ueberstunden, " . tpSqlDate('z.startzeit') . " AS day, " . tpSqlWeek('z.startzeit') . " AS week_number
                                FROM zeiterfassung z
                                JOIN users u ON z.user_id = u.id
                                WHERE u.supervisor_id = ?
                                ORDER BY z.startzeit DESC");
        $stmt->execute([$user_id]);
    }
    $zeiten = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Berechnung der Überstunden und detaillierte Daten
    $workHoursByUser = [];
    foreach ($zeiten as $zeit) {
        if (trim($zeit['beschreibung'] ?? '') === 'Feiertag') {
            continue;
        }
        if (empty($zeit['endzeit'])) {
            continue;
        }

        $userId = $zeit['user_id'];
        $day = $zeit['day'];
        $regelarbeitszeit = $zeit['regelarbeitszeit'] ?? 8.0;
        $vorherige_ueberstunden = $zeit['vorherige_ueberstunden'] ?? 0.0;

        if (!isset($workHoursByUser[$userId])) {
            $workHoursByUser[$userId] = [
                'username' => $zeit['username'],
                'days' => [],
                'regelarbeitszeit' => $regelarbeitszeit,
                'vorherige_ueberstunden' => $vorherige_ueberstunden,
                'total_hours' => 0,
                'total_days' => 0
            ];
        }

        if (!isset($workHoursByUser[$userId]['days'][$day])) {
            $workHoursByUser[$userId]['days'][$day] = 0;
            $workHoursByUser[$userId]['total_days']++;
        }

        $start = new DateTime($zeit['startzeit']);
        $end = new DateTime($zeit['endzeit']);
        $pauseMinuten = intval($zeit['pause']) ?: 0;

        $gesamtMinuten = max(0, floor(($end->getTimestamp() - $start->getTimestamp()) / 60) - $pauseMinuten);
        $workHoursByUser[$userId]['days'][$day] += $gesamtMinuten;
        $workHoursByUser[$userId]['total_hours'] += $gesamtMinuten / 60;
    }

    // Berechnung der Gesamtüberstunden pro Benutzer und Vorbereitung der detaillierten Daten
    foreach ($workHoursByUser as $userId => $data) {
        $totalOverMinutes = 0;
        $regelarbeitszeit = $data['regelarbeitszeit'];
        foreach ($data['days'] as $day => $totalMinutes) {
            $regularWorkingMinutesPerDay = $regelarbeitszeit * 60;

            $overMinutes = $totalMinutes - $regularWorkingMinutesPerDay;
            $totalOverMinutes += $overMinutes;
        }

        $totalOverMinutes = (int)round($totalOverMinutes + ($data['vorherige_ueberstunden'] * 60));

        $isNegative = $totalOverMinutes < 0;
        $totalOverHours = intdiv(abs($totalOverMinutes), 60);
        $remainingOverMinutes = abs($totalOverMinutes) % 60;
        $formattedOvertime = ($isNegative ? '-' : '') . sprintf("%02d:%02d", $totalOverHours, $remainingOverMinutes);

        $ueberstundenListe[$userId] = [
            'username' => $data['username'],
            'ueberstunden' => $formattedOvertime
        ];

        $detaillierteDaten[$userId] = [
            'username' => $data['username'],
            'regelarbeitszeit' => $data['regelarbeitszeit'],
            'total_hours' => round($data['total_hours'], 2),
            'total_days' => $data['total_days'],
            'avg_hours_per_day' => $data['total_days'] > 0 ? round($data['total_hours'] / $data['total_days'], 2) : 0,
            'ueberstunden' => $formattedOvertime
        ];
    }
}
?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Remove the specific background-color from [data-theme="dark"] as it's not needed */
        [data-theme="dark"] {
            color: hsl(var(--bc));
        }
        
        /* Existing styles */
        .modal-box {
            width: 90vw;
            max-width: 70vw;
            height: 70vh;
            max-height: 90vh;          
        }

        th {
            text-align: left !important;
        }

        /* Card animations */
        .stats-card {
            transition: transform 0.2s ease-in-out;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }

        /* Loading animation */
        .loading-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 200px;
        }

        /* Search bar styles */
        .search-container {
            margin-bottom: 1rem;
        }

        .search-input {
            width: 100%;
            max-width: 300px;
        }

        /* Filter badges */
        .filter-badge {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-badge:hover {
            transform: scale(1.05);
        }

        /* Chart container */
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
    </style>
    <!-- Remove the bg-base-200 class from body and add it to a wrapper div -->
    <div class="min-h-screen bg-base-100">
<!-- FEHLT NOCH    <?php include 'navigation.php'; ?> -->
        <div class="container mx-auto px-4 py-8">
            <h2 class="text-4xl font-bold mb-8 text-center"><?= SUPERVISOR_TIMES_TITLE ?></h2>

            <?php if ($user_role !== 'supervisor' && $user_role !== 'admin') : ?>
                <div class="alert alert-warning shadow-lg">
                    <div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span><?= NOT_SUPERVISOR_MESSAGE ?></span>
                    </div>
                </div>
            <?php else : ?>
                <!-- Search and Filter Section -->
                <div class="mb-6">
                    <div class="flex flex-wrap gap-4 items-center justify-between">
                        <div class="search-container">
                            <input type="text" 
                                id="searchInput" 
                                class="input input-bordered search-input" 
                                placeholder="<?= COMMON_SEARCH_BY_EMPLOYEE ?>">
                        </div>
                        <div class="flex gap-2 flex-wrap">
                            <span class="badge badge-primary filter-badge" data-filter="all"><?= COMMON_ALL ?></span>
                            <span class="badge badge-secondary filter-badge" data-filter="overtime"><?= OVERTIME ?></span>
                            <span class="badge badge-warning filter-badge" data-filter="undertime"><?= SUPERVISOR_UNDERTIME ?></span>
                        </div>
                    </div>
                </div>

                <?php if (false) : ?>
                <div class="card bg-base-100 shadow-xl mb-8">
                    <div class="card-body">
                        <h3 class="card-title text-xl">
                            <i class="fas fa-users-cog mr-2"></i>Mitarbeiter verwalten
                        </h3>
                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                            <form id="supervisorCreateUserForm" class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
                                <div class="form-control">
                                    <label class="label py-1"><span class="label-text">Benutzername</span></label>
                                    <input type="text" name="username" class="input input-bordered input-sm" required>
                                </div>
                                <div class="form-control">
                                    <label class="label py-1"><span class="label-text">E-Mail</span></label>
                                    <input type="email" name="email" class="input input-bordered input-sm" required>
                                </div>
                                <div class="form-control">
                                    <label class="label py-1"><span class="label-text">Startpasswort</span></label>
                                    <input type="password" name="password" class="input input-bordered input-sm" minlength="8" required>
                                </div>
                                <label class="label cursor-pointer justify-start gap-3">
                                    <input type="checkbox" name="force_password_change" class="checkbox checkbox-primary checkbox-sm">
                                    <span class="label-text whitespace-normal leading-snug"><?= FORM_FORCE_PASSWORD_CHANGE ?></span>
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm md:col-span-2">
                                    <i class="fas fa-user-plus"></i>
                                    Mitarbeiter erstellen
                                </button>
                            </form>

                            <form id="supervisorRenameUserForm" class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
                                <div class="form-control">
                                    <label class="label py-1"><span class="label-text">Mitarbeiter</span></label>
                                    <select name="user_id" class="select select-bordered select-sm" required>
                                        <option value="">Auswählen</option>
                                        <?php foreach ($managedUsers as $managedUser) : ?>
                                            <option value="<?= htmlspecialchars($managedUser['id']) ?>"><?= htmlspecialchars($managedUser['username']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label py-1"><span class="label-text">Neuer Benutzername</span></label>
                                    <input type="text" name="username" class="input input-bordered input-sm" required>
                                </div>
                                <button type="submit" class="btn btn-outline btn-primary btn-sm md:col-span-2">
                                    <i class="fas fa-user-edit"></i>
                                    Benutzername ändern
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <?php endif; ?>
                <div class="card bg-base-100 shadow-xl mb-8">
                    <div class="card-body">
                        <h3 class="card-title text-xl">
                            <i class="fas fa-plus-circle mr-2"></i><?= COMMON_MISSING_DAY ?>
                        </h3>
                        <form id="supervisorAddRecordForm" class="grid grid-cols-1 md:grid-cols-8 gap-3 items-end">
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= AUDIT_EMPLOYEE ?></span></label>
                                <select name="user_id" class="select select-bordered select-sm" required>
                                    <option value=""><?= COMMON_SELECT ?></option>
                                    <?php foreach ($managedUsers as $managedUser) : ?>
                                        <option value="<?= htmlspecialchars($managedUser['id']) ?>"><?= htmlspecialchars($managedUser['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= FORM_START ?></span></label>
                                <input type="datetime-local" name="startzeit" class="input input-bordered input-sm" required>
                            </div>
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= FORM_END ?></span></label>
                                <input type="datetime-local" name="endzeit" class="input input-bordered input-sm" required>
                            </div>
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= FORM_BREAK_MINUTES ?></span></label>
                                <input type="number" name="manual_pause" min="0" step="1" value="0" class="input input-bordered input-sm">
                            </div>
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= FORM_LOCATION ?></span></label>
                                <select name="standort" class="select select-bordered select-sm">
                                    <option value="<?= LOCATION_OFFICE_VALUE ?>"><?= LOCATION_OFFICE ?></option>
                                    <option value="<?= LOCATION_HOME_OFFICE_VALUE ?>"><?= LOCATION_HOME_OFFICE ?></option>
                                    <option value="<?= LOCATION_BUSINESS_TRIP_VALUE ?>"><?= LOCATION_BUSINESS_TRIP ?></option>
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= FORM_COMMENT ?></span></label>
                                <input type="text" name="beschreibung" class="input input-bordered input-sm">
                            </div>
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= COMMON_CHANGE_REASON ?></span></label>
                                <input type="text" name="audit_reason" class="input input-bordered input-sm" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i>
                                <?= COMMON_ADD_LATER ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card bg-base-100 shadow-xl mb-8">
                    <div class="card-body">
                        <h3 class="card-title text-xl">
                            <i class="fas fa-file-pdf mr-2"></i>PDF Export
                        </h3>
                        <div class="flex flex-col md:flex-row gap-3 items-end">
                            <div class="form-control w-full md:max-w-xs">
                                <label class="label py-1"><span class="label-text"><?= AUDIT_EMPLOYEE ?></span></label>
                                <select id="pdfExportUser" class="select select-bordered select-sm">
                                    <option value=""><?= COMMON_SELECT_EMPLOYEE ?></option>
                                    <?php foreach ($managedUsers as $managedUser) : ?>
                                        <option value="<?= htmlspecialchars($managedUser['id']) ?>"><?= htmlspecialchars($managedUser['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" id="exportUserPdfButton" class="btn btn-outline btn-error btn-sm">
                                <i class="fas fa-file-pdf"></i>
                                <?= SUPERVISOR_PDF_EMPLOYEE ?>
                            </button>
                            <button type="button" id="exportUserPdfAButton" class="btn btn-error btn-sm">
                                <i class="fas fa-file-pdf"></i>
                                <?= SUPERVISOR_PDFA_EMPLOYEE ?>
                            </button>
                            <a href="export_pdf.php?mode=all" class="btn btn-error btn-sm">
                                <i class="fas fa-file-pdf"></i>
                                <?= SUPERVISOR_PDF_ALL ?>
                            </a>
                            <a href="export_pdf.php?mode=all&pdfa=1" class="btn btn-error btn-sm">
                                <i class="fas fa-file-pdf"></i>
                                <?= SUPERVISOR_PDFA_ALL ?>
                            </a>
                        </div>
                        <div class="divider"></div>
                        <form id="mailPdfReportsForm" class="grid grid-cols-1 lg:grid-cols-[minmax(240px,1fr)_220px_auto] gap-4 items-end">
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= SUPERVISOR_EMAIL_EMPLOYEES ?></span></label>
                                <select name="user_ids[]" class="select select-bordered min-h-32" multiple size="6" required>
                                    <?php foreach ($managedUsers as $managedUser) : ?>
                                        <option value="<?= htmlspecialchars($managedUser['id']) ?>"><?= htmlspecialchars($managedUser['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="label py-1"><span class="label-text"><?= COMMON_FORMAT ?></span></label>
                                <div class="join">
                                    <label class="btn btn-sm join-item">
                                        <input type="radio" name="pdf_format" value="pdf" class="radio radio-xs mr-2" checked>
                                        PDF
                                    </label>
                                    <label class="btn btn-sm join-item">
                                        <input type="radio" name="pdf_format" value="pdfa" class="radio radio-xs mr-2">
                                        PDF/A
                                    </label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-envelope"></i>
                                <?= COMMON_SEND_BY_EMAIL ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="mb-8">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="card bg-base-100 shadow-xl">
                            <div class="card-body">
                                <h3 class="card-title"><?= SUPERVISOR_OVERTIME_OVERVIEW ?></h3>
                                <div class="chart-container">
                                    <canvas id="overtimeChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="card bg-base-100 shadow-xl">
                            <div class="card-body">
                                <h3 class="card-title"><?= SUPERVISOR_REGULAR_HOURS_BY_EMPLOYEE ?></h3>
                                <div class="chart-container">
                                    <canvas id="regularHoursChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Overtime Cards Section -->
                <div class="mb-12">
                    <h3 class="text-2xl font-semibold mb-6">
                        <i class="fas fa-hourglass mr-2"></i><?= TOTAL_OVERTIME_TITLE ?>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" id="overtimeCards">
                        <?php if (is_array($ueberstundenListe) && !empty($ueberstundenListe)) : ?>
                            <?php foreach ($ueberstundenListe as $userId => $data) : ?>
                                <div class="card bg-base-100 shadow-xl hover:shadow-2xl transition-shadow duration-300 fade-in stats-card">
                                    <div class="card-body">
                                        <h2 class="card-title text-lg"><?= htmlspecialchars($data['username']) ?></h2>
                                        <p class="text-3xl font-bold <?= substr($data['ueberstunden'], 0, 1) === '-' ? 'text-error' : 'text-success' ?>">
                                            <?= htmlspecialchars($data['ueberstunden']) ?>
                                        </p>
                                        <div class="card-actions justify-end">
                                            <button class="btn btn-primary btn-sm" onclick="showDetails('<?= $userId ?>')"><?= COMMON_DETAILS ?></button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Time Records Table Section -->
                <div class="mb-8">
                    <h3 class="text-2xl font-semibold mb-6">
                        <i class="fas fa-clock mr-2"></i><?= ACTUAL_WORKED_TIMES ?>
                    </h3>
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <!-- Search and Filter Bar -->
                            <div class="flex flex-wrap gap-4 mb-4">
                                <div class="form-control w-full max-w-xs">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-search"></i>
                                        </span>
                                        <input type="text" 
                                            id="userSearchInput" 
                                            class="input input-bordered w-full" 
                                            placeholder="<?= COMMON_SEARCH_EMPLOYEE ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="table w-full">
                                    <thead>
                                        <tr>
                                            <th class="w-8"></th> <!-- Expand/Collapse Icon -->
                                            <th><?= TABLE_HEADER_USERNAME ?></th>
                                            <th><?= TABLE_HEADER_DATE ?></th>
                                            <th><?= TABLE_HEADER_TOTAL_DURATION ?></th>
                                            <th><?= TABLE_HEADER_TOTAL_BREAK ?></th>
                                            <th><?= TABLE_HEADER_LOCATION ?></th>
                                            <th><?= TABLE_HEADER_OVERTIME ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="timeRecordsBody">
                                        <?php
                                        // Group entries by user and date
                                        $groupedEntries = [];
                                        foreach ($zeiten as $zeit) {
                                            if (empty($zeit['endzeit'])) {
                                                continue;
                                            }

                                            $userId = $zeit['user_id'];
                                            $date = date('Y-m-d', strtotime($zeit['startzeit']));
                                            $key = $userId . '_' . $date;
                                            
                                            if (!isset($groupedEntries[$key])) {
                                                $groupedEntries[$key] = [
                                                    'user_id' => $userId,
                                                    'username' => $zeit['username'],
                                                    'date' => $date,
                                                    'regelarbeitszeit' => $zeit['regelarbeitszeit'],
                                                    'entries' => [],
                                                    'total_duration' => 0,
                                                    'total_break' => 0
                                                ];
                                            }
                                            
                                            // Calculate duration for this entry
                                            $start = new DateTime($zeit['startzeit']);
                                            $end = new DateTime($zeit['endzeit']);
                                            $duration = ($end->getTimestamp() - $start->getTimestamp()) / 60;
                                            $break = intval($zeit['pause']);
                                            
                                            // Add entry with its individual duration minus break
                                            $groupedEntries[$key]['entries'][] = [
                                                'id' => $zeit['id'],
                                                'startzeit' => $zeit['startzeit'],
                                                'endzeit' => $zeit['endzeit'],
                                                'duration' => $duration - $break, // Individual duration minus break
                                                'pause' => $break,
                                                'standort' => $zeit['standort'],
                                                'beschreibung' => $zeit['beschreibung'] ?? ''
                                            ];
                                            
                                            // Update totals
                                            $groupedEntries[$key]['total_duration'] += $duration - $break; // Add duration minus break
                                            $groupedEntries[$key]['total_break'] += $break;
                                        }

                                        // Output grouped entries
                                        $itemsPerPage = 10; // Number of items per page
                                        $totalRecords = count($groupedEntries);
                                        $totalPages = max(1, ceil($totalRecords / $itemsPerPage));
                                        $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                                        $currentPage = max(1, min($currentPage, $totalPages));
                                        $start = ($currentPage - 1) * $itemsPerPage;
                                        $pagedEntries = array_slice($groupedEntries, $start, $itemsPerPage, true);

                                        foreach ($pagedEntries as $group) {
                                            $totalDuration = (int)round($group['total_duration']); // Already includes break deduction
                                            $hours = intdiv($totalDuration, 60);
                                            $minutes = $totalDuration % 60;
                                            $overtime = (int)round($totalDuration - ($group['regelarbeitszeit'] * 60));
                                            $overtimeHours = intdiv(abs($overtime), 60);
                                            $overtimeMinutes = abs($overtime) % 60;

                                            // Summary row
                                            echo '<tr class="group-header hover:bg-base-200 cursor-pointer" data-user-id="' . $group['user_id'] . '" data-date="' . $group['date'] . '">
                                                    <td><i class="fas fa-chevron-right transition-transform duration-200"></i></td>
                                                    <td>' . htmlspecialchars($group['username']) . '</td>
                                                    <td>' . date('d.m.Y', strtotime($group['date'])) . '</td>
                                                    <td>' . sprintf("%02d:%02d", $hours, $minutes) . '</td>
                                                    <td>' . $group['total_break'] . ' min</td>
                                                    <td>' . (count($group['entries']) > 1 ? 'Mehrere' : htmlspecialchars($group['entries'][0]['standort'])) . '</td>
                                                    <td class="' . ($overtime >= 0 ? 'text-success' : 'text-error') . '">
                                                        ' . ($overtime >= 0 ? '+' : '-') . sprintf("%02d:%02d", $overtimeHours, $overtimeMinutes) . '
                                                    </td>
                                                </tr>';

                                            // Detail rows (initially hidden)
                                            echo '<tr class="detail-row hidden" data-parent="' . $group['user_id'] . '_' . $group['date'] . '">
                                                    <td colspan="7" class="p-0">
                                                        <div class="bg-base-200/50 p-4">
                                                            <table class="table w-full">
                                                                <thead>
                                                                    <tr>
                                                                        <th>' . TABLE_HEADER_START_TIME . '</th>
                                                                        <th>' . TABLE_HEADER_END_TIME . '</th>
                                                                        <th>' . TABLE_HEADER_DURATION . '</th>
                                                                        <th>' . TABLE_HEADER_BREAK . '</th>
                                                                        <th>' . TABLE_HEADER_LOCATION . '</th>
                                                                        <th>' . TABLE_HEADER_COMMENT . '</th>
                                                                        <th>' . TABLE_HEADER_ACTIONS . '</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>';

                                            foreach ($group['entries'] as $entry) {
                                                $entryStart = new DateTime($entry['startzeit']);
                                                $entryEnd = new DateTime($entry['endzeit']);
                                                $entryDuration = (int)round($entry['duration']);
                                                $entryHours = intdiv($entryDuration, 60);
                                                $entryMinutes = $entryDuration % 60;

                                                echo '<tr class="supervisor-edit-row" data-id="' . htmlspecialchars($entry['id']) . '">
                                                        <td><input type="datetime-local" name="startzeit" class="input input-bordered input-xs w-44" value="' . $entryStart->format('Y-m-d\TH:i') . '"></td>
                                                        <td><input type="datetime-local" name="endzeit" class="input input-bordered input-xs w-44" value="' . $entryEnd->format('Y-m-d\TH:i') . '"></td>
                                                        <td>' . sprintf("%02d:%02d", $entryHours, $entryMinutes) . '</td>
                                                        <td><input type="number" name="pause" min="0" class="input input-bordered input-xs w-20" value="' . htmlspecialchars($entry['pause']) . '"></td>
                                                        <td>
                                                            <select name="standort" class="select select-bordered select-xs w-32">
                                                                <option value="' . LOCATION_OFFICE_VALUE . '" ' . ($entry['standort'] == LOCATION_OFFICE_VALUE ? 'selected' : '') . '>' . LOCATION_OFFICE . '</option>
                                                                <option value="' . LOCATION_HOME_OFFICE_VALUE . '" ' . ($entry['standort'] == LOCATION_HOME_OFFICE_VALUE ? 'selected' : '') . '>' . LOCATION_HOME_OFFICE . '</option>
                                                                <option value="' . LOCATION_BUSINESS_TRIP_VALUE . '" ' . ($entry['standort'] == LOCATION_BUSINESS_TRIP_VALUE ? 'selected' : '') . '>' . LOCATION_BUSINESS_TRIP . '</option>
                                                            </select>
                                                        </td>
                                                        <td><input type="text" name="beschreibung" class="input input-bordered input-xs w-full" value="' . htmlspecialchars($entry['beschreibung'] ?? '') . '"></td>
                                                        <td>
                                                            <button type="button" class="btn btn-primary btn-xs supervisor-save-record">
                                                                <i class="fas fa-save"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-error btn-xs supervisor-delete-record">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </td>
                                                    </tr>';
                                            }

                                            echo '</tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-center mt-4">
                        <div class="btn-group">
                            <?php
                            // Vorherige-Seite-Button
                            $prevClass = $currentPage == 1 ? ' btn-disabled' : '';
                            echo "<a href='?page=".($currentPage-1)."' class='btn btn-sm$prevClass'>&laquo;</a>";

                            // Maximal anzuzeigende Seitenzahlen
                            $maxVisible = 5;
                            $start = max(1, min($currentPage - floor($maxVisible/2), $totalPages - $maxVisible + 1));
                            $end = min($start + $maxVisible - 1, $totalPages);

                            // Erste Seite anzeigen, wenn wir nicht bei 1 beginnen
                            if ($start > 1) {
                                echo "<a href='?page=1' class='btn btn-sm'>1</a>";
                                if ($start > 2) {
                                    echo "<span class='btn btn-sm btn-disabled'>...</span>";
                                }
                            }

                            // Seitenzahlen
                            for ($i = $start; $i <= $end; $i++) {
                                $activeClass = $i == $currentPage ? ' btn-active' : '';
                                echo "<a href='?page=$i' class='btn btn-sm$activeClass'>$i</a>";
                            }

                            // Letzte Seite anzeigen, wenn wir nicht beim Maximum enden
                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) {
                                    echo "<span class='btn btn-sm btn-disabled'>...</span>";
                                }
                                echo "<a href='?page=$totalPages' class='btn btn-sm'>$totalPages</a>";
                            }

                            // Nächste-Seite-Button
                            $nextClass = $currentPage == $totalPages ? ' btn-disabled' : '';
                            echo "<a href='?page=".($currentPage+1)."' class='btn btn-sm$nextClass'>&raquo;</a>";
                            ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced Modal -->
    <dialog id="details_modal" class="modal">
        <div class="modal-box w-11/12 max-w-7xl bg-base-200">
            <div class="modal-header flex justify-between items-center mb-6">
                <h3 class="font-bold text-2xl" id="modal_title"><?= SUPERVISOR_DETAIL_TITLE ?></h3>
                <form method="dialog">
                    <button class="btn btn-circle btn-ghost">✕</button>
                </form>
            </div>
            <div id="modal_content" class="space-y-6">
                <!-- Content will be dynamically inserted here -->
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button><?= BUTTON_CLOSE ?></button>
        </form>
    </dialog>

    <script>
        const supervisorI18n = {
            overviewFor: <?= json_encode(SUPERVISOR_OVERVIEW_FOR) ?>,
            regularWorkingHours: <?= json_encode(REGULAR_WORKING_HOURS) ?>,
            hoursPerDay: <?= json_encode(DASHBOARD_HOURS_PER_DAY) ?>,
            workedDays: <?= json_encode(SUPERVISOR_WORKED_DAYS) ?>,
            totalHours: <?= json_encode(SUPERVISOR_TOTAL_HOURS) ?>,
            inThisPeriod: <?= json_encode(SUPERVISOR_IN_THIS_PERIOD) ?>,
            workingHours: <?= json_encode(SUPERVISOR_WORKING_HOURS) ?>,
            noticeTitle: <?= json_encode(SUPERVISOR_NOTICE_TITLE) ?>,
            overtime: <?= json_encode(OVERTIME) ?>,
            undertime: <?= json_encode(SUPERVISOR_UNDERTIME) ?>,
            notice: <?= json_encode(SUPERVISOR_DYNAMIC_NOTICE) ?>,
            noDetails: <?= json_encode(SUPERVISOR_NO_DETAILS) ?>,
            chartHours: <?= json_encode(SUPERVISOR_CHART_HOURS) ?>,
            chartRegularHours: <?= json_encode(SUPERVISOR_CHART_REGULAR_HOURS) ?>,
            chartHoursPerDay: <?= json_encode(SUPERVISOR_CHART_HOURS_PER_DAY) ?>,
            error: <?= json_encode(ERROR_MODAL_TITLE) ?>,
            genericError: <?= json_encode(ERROR_GENERIC) ?>,
            selectEmployeeTitle: <?= json_encode(COMMON_SELECT_EMPLOYEE) ?>,
            selectEmployeeText: <?= json_encode(VALIDATION_SELECT_EMPLOYEE) ?>,
            selectAtLeastOneEmployee: <?= json_encode(VALIDATION_SELECT_AT_LEAST_ONE_EMPLOYEE) ?>,
            sending: <?= json_encode(SUPERVISOR_SENDING) ?>,
            changeReason: <?= json_encode(SUPERVISOR_EDIT_REASON_TITLE) ?>,
            editReasonLabel: <?= json_encode(SUPERVISOR_EDIT_REASON_LABEL) ?>,
            deleteReasonLabel: <?= json_encode(SUPERVISOR_DELETE_REASON_LABEL) ?>,
            reasonRequired: <?= json_encode(VALIDATION_REASON_REQUIRED) ?>,
            save: <?= json_encode(COMMON_SAVE) ?>,
            cancel: <?= json_encode(BUTTON_CANCEL) ?>,
            deleteEntryTitle: <?= json_encode(CONFIRM_DELETE_ENTRY_TITLE) ?>,
            deleteEntryText: <?= json_encode(CONFIRM_DELETE_ENTRY_PERMANENT_TEXT) ?>,
            deleteEntryButton: <?= json_encode(CONFIRM_DELETE_ENTRY_BUTTON) ?>,
            deleted: <?= json_encode(ENTRY_DELETED_TITLE) ?>,
            deleteFailed: <?= json_encode(ENTRY_DELETE_FAILED) ?>
        };

        const detaillierteDaten = <?= json_encode($detaillierteDaten) ?>;
        const itemsPerPage = 10; // Anzahl der Einträge pro Seite
        let currentPage = 1;
        const zeiten = <?= json_encode($zeiten) ?>; // PHP-Array in JavaScript-Variable umwandeln

        function showDetails(userId) {
            const modal = document.getElementById('details_modal');
            const modalContent = document.getElementById('modal_content');
            const modalTitle = document.getElementById('modal_title');
            const userData = detaillierteDaten[userId];

            if (userData) {
                modalTitle.textContent = supervisorI18n.overviewFor.replace('%s', userData.username);
                modalContent.innerHTML = `
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="stat bg-base-100 rounded-box shadow-lg">
                    <div class="stat-figure text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block w-8 h-8 stroke-current"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                    </div>
                    <div class="stat-title">${supervisorI18n.regularWorkingHours}</div>
                    <div class="stat-value">${userData.regelarbeitszeit}h</div>
                    <div class="stat-desc">${supervisorI18n.hoursPerDay}</div>
                </div>
                
                <div class="stat bg-base-100 rounded-box shadow-lg">
                    <div class="stat-figure text-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block w-8 h-8 stroke-current"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                    </div>
                    <div class="stat-title">${supervisorI18n.workedDays}</div>
                    <div class="stat-value">${userData.total_days}</div>
                    <div class="stat-desc">${supervisorI18n.inThisPeriod}</div>
                </div>

                <div class="stat bg-base-100 rounded-box shadow-lg">
                    <div class="stat-figure text-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block w-8 h-8 stroke-current"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <div class="stat-title">Gesamtarbeitsstunden</div>
                    <div class="stat-value">${userData.total_hours.toFixed(2)}</div>
                    <div class="stat-desc">${supervisorI18n.totalHours}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="stat bg-base-100 rounded-box shadow-lg">
                    <div class="stat-figure text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block w-8 h-8 stroke-current"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                    </div>
                    <div class="stat-title"><?= DASHBOARD_AVERAGE_DAILY_HOURS ?></div>
                    <div class="stat-value">${userData.avg_hours_per_day.toFixed(2)}h</div>
                    <div class="stat-desc">${supervisorI18n.workingHours}</div>
                </div>

                <div class="stat bg-base-100 rounded-box shadow-lg">
                    <div class="stat-figure ${userData.ueberstunden.startsWith('-') ? 'text-error' : 'text-success'}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block w-8 h-8 stroke-current"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div class="stat-title">${supervisorI18n.overtime}</div>
                    <div class="stat-value ${userData.ueberstunden.startsWith('-') ? 'text-error' : 'text-success'}">${userData.ueberstunden}</div>
                    <div class="stat-desc">${userData.ueberstunden.startsWith('-') ? supervisorI18n.undertime : supervisorI18n.overtime}</div>
                </div>
            </div>

            <div class="alert alert-info shadow-lg mt-6">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <div>
                    <h3 class="font-bold">${supervisorI18n.noticeTitle}</h3>
                    <div class="text-xs">${supervisorI18n.notice}</div>
                </div>
            </div>
        `;
            } else {
                modalContent.innerHTML = `<div class="alert alert-warning">${supervisorI18n.noDetails}</div>`;
            }

            modal.showModal();
        }

        function formatTime(minutes) {
            const hours = Math.floor(minutes / 60);
            const mins = Math.round(minutes % 60);
            return `${hours}h ${mins}m`;
        }

        function displayTable(page) {
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) {
                return;
            }

            const startIndex = (page - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const pageItems = zeiten.slice(startIndex, endIndex);

            tableBody.innerHTML = '';

            pageItems.forEach(zeit => {
                const start = new Date(zeit.startzeit);
                const end = new Date(zeit.endzeit);
                const interval = (end - start) / (1000 * 60); // Differenz in Minuten
                const pauseMinuten = parseInt(zeit.pause) || 0;

                const gesamtMinuten = interval - pauseMinuten;
                const dauer = formatTime(gesamtMinuten);

                const regelarbeitszeit = zeit.regelarbeitszeit || 8.0;
                const regularWorkingMinutesPerDay = regelarbeitszeit * 60;
                const ueberstunden = gesamtMinuten - regularWorkingMinutesPerDay;
                const ueberstundenFormat = formatTime(Math.abs(ueberstunden));

                const row = `
                <tr class="hover:bg-base-200 transition-colors duration-200">
                    <td>${zeit.username}</td>
                    <td>${zeit.week_number}</td>
                    <td>${start.toLocaleString()}</td>
                    <td>${end.toLocaleString()}</td>
                    <td>${dauer}</td>
                    <td>${zeit.pause} min</td>
                    <td>${zeit.standort}</td>
                    <td class="${ueberstunden >= 0 ? 'text-success' : 'text-error'} font-semibold">${ueberstunden >= 0 ? '+' : '-'}${ueberstundenFormat}</td>
                </tr>
            `;
                tableBody.innerHTML += row;
            });

            updatePagination();
        }

        function updatePagination() {
            const paginationContainer = document.getElementById('pagination');
            const totalPages = Math.ceil(zeiten.length / itemsPerPage);

            let paginationHTML = `
            <button class="btn btn-sm" onclick="changePage(1)" ${currentPage === 1 ? 'disabled' : ''}>«</button>
            <button class="btn btn-sm" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>‹</button>
        `;

            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

            if (endPage - startPage + 1 < maxVisiblePages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }

            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `
                <button class="btn btn-sm ${i === currentPage ? 'btn-active' : ''}" onclick="changePage(${i})">${i}</button>
            `;
            }

            paginationHTML += `
            <button class="btn btn-sm" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>›</button>
            <button class="btn btn-sm" onclick="changePage(${totalPages})" ${currentPage === totalPages ? 'disabled' : ''}>»</button>
        `;

            paginationContainer.innerHTML = paginationHTML;
        }

        function changePage(page) {
            currentPage = page;
            displayTable(currentPage);
        }

        // Initialize Charts
        function initializeCharts() {
            // Convert the PHP array to the correct format for charts
            const overtimeData = Object.values(<?= json_encode($ueberstundenListe) ?>).map(user => ({
                label: user.username,
                value: parseFloat(user.ueberstunden.replace(':', '.').replace('-', '-'))
            }));

            // Overtime Chart
            new Chart(document.getElementById('overtimeChart'), {
                type: 'bar',
                data: {
                    labels: overtimeData.map(d => d.label),
                    datasets: [{
                        label: supervisorI18n.overtime,
                        data: overtimeData.map(d => d.value),
                        backgroundColor: overtimeData.map(d => d.value >= 0 ? 
                            'rgba(72, 187, 120, 0.7)' : 'rgba(245, 101, 101, 0.7)'),
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: supervisorI18n.chartHours
                            }
                        }
                    }
                }
            });

            // Convert the PHP array to the correct format for charts
            const workingHoursData = Object.values(<?= json_encode($detaillierteDaten) ?>).map(user => ({
                label: user.username,
                value: user.total_hours
            }));

            // Working Hours Chart
            new Chart(document.getElementById('regularHoursChart'), {
                type: 'bar',
                data: {
                    labels: Object.values(<?= json_encode($detaillierteDaten) ?>).map(d => d.username),
                    datasets: [{
                        label: supervisorI18n.chartRegularHours,
                        data: Object.values(<?= json_encode($detaillierteDaten) ?>).map(d => d.regelarbeitszeit),
                        backgroundColor: 'rgba(66, 153, 225, 0.7)',
                        borderColor: 'rgba(66, 153, 225, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: supervisorI18n.chartHoursPerDay
                            }
                        }
                    }
                }
            });
        }

        // Search and Filter Functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            filterCards(searchTerm);
        });

        document.querySelectorAll('.filter-badge').forEach(badge => {
            badge.addEventListener('click', function() {
                const filter = this.dataset.filter;
                document.querySelectorAll('.filter-badge').forEach(b => b.classList.remove('badge-accent'));
                this.classList.add('badge-accent');
                filterByType(filter);
            });
        });

        function filterCards(searchTerm) {
            const cards = document.querySelectorAll('#overtimeCards .card');
            cards.forEach(card => {
                const username = card.querySelector('.card-title').textContent.toLowerCase();
                card.style.display = username.includes(searchTerm) ? '' : 'none';
            });
        }

        function filterByType(type) {
            const cards = document.querySelectorAll('#overtimeCards .card');
            cards.forEach(card => {
                const hours = parseFloat(card.querySelector('.text-3xl').textContent.replace(':', '.'));
                if (type === 'all' || 
                    (type === 'overtime' && hours > 0) || 
                    (type === 'undertime' && hours < 0)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Initialize everything when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            displayTable(currentPage);
        });

        // Add these JavaScript functions after your existing script
        document.addEventListener('DOMContentLoaded', function() {
            function submitSupervisorUserForm(form, action) {
                const formData = new FormData(form);
                formData.append('action', action);

                fetch('save.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || supervisorI18n.genericError);
                    }

                    Swal.fire({
                        icon: 'success',
                        title: data.message,
                        showConfirmButton: false,
                        timer: 1200
                    }).then(() => window.location.reload());
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: supervisorI18n.error,
                        text: error.message,
                        confirmButtonText: 'OK'
                    });
                });
            }

            const supervisorCreateUserForm = document.getElementById('supervisorCreateUserForm');
            if (supervisorCreateUserForm) {
                supervisorCreateUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitSupervisorUserForm(this, 'supervisor_create_user');
                });
            }

            const supervisorRenameUserForm = document.getElementById('supervisorRenameUserForm');
            if (supervisorRenameUserForm) {
                supervisorRenameUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitSupervisorUserForm(this, 'supervisor_update_username');
                });
            }

            const supervisorAddRecordForm = document.getElementById('supervisorAddRecordForm');
            if (supervisorAddRecordForm) {
                supervisorAddRecordForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    formData.append('action', 'manual_add');
                    formData.append('pause', this.querySelector('[name="manual_pause"]').value || '0');

                    fetch('save.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || supervisorI18n.genericError);
                        }

                        Swal.fire({
                            icon: 'success',
                            title: data.message,
                            showConfirmButton: false,
                            timer: 1200
                        }).then(() => window.location.reload());
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: supervisorI18n.error,
                            text: error.message,
                            confirmButtonText: 'OK'
                        });
                    });
                });
            }

            const mailPdfReportsForm = document.getElementById('mailPdfReportsForm');
            if (mailPdfReportsForm) {
                mailPdfReportsForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const selectedUsers = Array.from(this.querySelector('[name="user_ids[]"]').selectedOptions);
                    if (selectedUsers.length === 0) {
                        Swal.fire({
                            icon: 'warning',
                            title: supervisorI18n.selectEmployeeTitle,
                            text: supervisorI18n.selectAtLeastOneEmployee,
                            confirmButtonText: 'OK'
                        });
                        return;
                    }

                    const submitButton = this.querySelector('button[type="submit"]');
                    const originalButtonHtml = submitButton.innerHTML;
                    submitButton.disabled = true;
                    submitButton.innerHTML = `<span class="loading loading-spinner loading-xs"></span> ${supervisorI18n.sending}`;

                    const formData = new FormData(this);
                    formData.append('action', 'mail_pdf_reports');

                    fetch('save.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || supervisorI18n.genericError);
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Versand abgeschlossen',
                            text: data.message,
                            confirmButtonText: 'OK'
                        });
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Versand fehlgeschlagen',
                            text: error.message,
                            confirmButtonText: 'OK'
                        });
                    })
                    .finally(() => {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalButtonHtml;
                    });
                });
            }

            const exportUserPdfButton = document.getElementById('exportUserPdfButton');
            if (exportUserPdfButton) {
                exportUserPdfButton.addEventListener('click', function() {
                    const selectedUser = document.getElementById('pdfExportUser').value;
                    if (!selectedUser) {
                        Swal.fire({
                            icon: 'warning',
                            title: supervisorI18n.selectEmployeeTitle,
                            text: supervisorI18n.selectEmployeeText,
                            confirmButtonText: 'OK'
                        });
                        return;
                    }

                    window.location.href = `export_pdf.php?user_id=${encodeURIComponent(selectedUser)}`;
                });
            }

            const exportUserPdfAButton = document.getElementById('exportUserPdfAButton');
            if (exportUserPdfAButton) {
                exportUserPdfAButton.addEventListener('click', function() {
                    const selectedUser = document.getElementById('pdfExportUser').value;
                    if (!selectedUser) {
                        Swal.fire({
                            icon: 'warning',
                            title: supervisorI18n.selectEmployeeTitle,
                            text: supervisorI18n.selectEmployeeText,
                            confirmButtonText: 'OK'
                        });
                        return;
                    }

                    window.location.href = `export_pdf.php?user_id=${encodeURIComponent(selectedUser)}&pdfa=1`;
                });
            }

            document.querySelectorAll('.supervisor-save-record').forEach(button => {
                button.addEventListener('click', async function() {
                    const row = this.closest('.supervisor-edit-row');
                    const reasonResult = await Swal.fire({
                        icon: 'question',
                        title: supervisorI18n.changeReason,
                        input: 'text',
                        inputLabel: supervisorI18n.editReasonLabel,
                        inputValidator: value => value.trim() ? undefined : supervisorI18n.reasonRequired,
                        showCancelButton: true,
                        confirmButtonText: supervisorI18n.save,
                        cancelButtonText: supervisorI18n.cancel
                    });

                    if (!reasonResult.isConfirmed) {
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'update_record');
                    formData.append('id', row.dataset.id);
                    formData.append('startzeit', row.querySelector('[name="startzeit"]').value);
                    formData.append('endzeit', row.querySelector('[name="endzeit"]').value);
                    formData.append('pause', row.querySelector('[name="pause"]').value);
                    formData.append('standort', row.querySelector('[name="standort"]').value);
                    formData.append('beschreibung', row.querySelector('[name="beschreibung"]').value);
                    formData.append('audit_reason', reasonResult.value.trim());

                    fetch('save.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || supervisorI18n.genericError);
                        }

                        Swal.fire({
                            icon: 'success',
                            title: data.message,
                            showConfirmButton: false,
                            timer: 1200
                        }).then(() => window.location.reload());
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: supervisorI18n.error,
                            text: error.message,
                            confirmButtonText: 'OK'
                        });
                    });
                });
            });

            document.querySelectorAll('.supervisor-delete-record').forEach(button => {
                button.addEventListener('click', function() {
                    const row = this.closest('.supervisor-edit-row');

                    Swal.fire({
                        title: supervisorI18n.deleteEntryTitle,
                        text: supervisorI18n.deleteEntryText,
                        icon: 'warning',
                        input: 'text',
                        inputLabel: supervisorI18n.deleteReasonLabel,
                        inputValidator: value => value.trim() ? undefined : supervisorI18n.reasonRequired,
                        showCancelButton: true,
                        confirmButtonText: supervisorI18n.deleteEntryButton,
                        cancelButtonText: supervisorI18n.cancel,
                        confirmButtonColor: '#d33'
                    }).then(result => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        const formData = new FormData();
                        formData.append('delete', 'true');
                        formData.append('id', row.dataset.id);
                        formData.append('audit_reason', result.value.trim());

                        fetch('save.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.text())
                        .then(data => {
                            if (data.trim() !== 'Successfully deleted') {
                                throw new Error(data || supervisorI18n.deleteFailed);
                            }

                            Swal.fire({
                                icon: 'success',
                                title: supervisorI18n.deleted,
                                showConfirmButton: false,
                                timer: 1200
                            }).then(() => window.location.reload());
                        })
                        .catch(error => {
                            Swal.fire({
                                icon: 'error',
                                title: supervisorI18n.error,
                                text: error.message,
                                confirmButtonText: 'OK'
                            });
                        });
                    });
                });
            });

            // User search functionality
            const userSearchInput = document.getElementById('userSearchInput');
            userSearchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('.group-header');
                
                rows.forEach(row => {
                    const username = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                    const shouldShow = username.includes(searchTerm);
                    row.style.display = shouldShow ? '' : 'none';
                    
                    // Hide/show corresponding detail row
                    const detailRow = document.querySelector(`.detail-row[data-parent="${row.dataset.userId}_${row.dataset.date}"]`);
                    if (detailRow && detailRow.classList.contains('show')) {
                        detailRow.style.display = shouldShow ? '' : 'none';
                    }
                });
            });

            // Expand/collapse functionality
            document.querySelectorAll('.group-header').forEach(header => {
                header.addEventListener('click', function() {
                    const userId = this.dataset.userId;
                    const date = this.dataset.date;
                    const detailRow = document.querySelector(`.detail-row[data-parent="${userId}_${date}"]`);
                    const chevron = this.querySelector('.fa-chevron-right');
                    
                    detailRow.classList.toggle('hidden');
                    chevron.style.transform = detailRow.classList.contains('hidden') ? '' : 'rotate(90deg)';
                });
            });
        });
    </script>
