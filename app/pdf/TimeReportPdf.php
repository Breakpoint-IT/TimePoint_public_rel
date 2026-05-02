<?php

function tpPdfFetchUser(int $userId): ?array
{
    global $conn;

    $stmt = $conn->prepare('SELECT id, username, email, regelarbeitszeit, ueberstunden, vacation_days_per_year FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function tpPdfFetchRecords(int $userId): array
{
    global $conn;

    $stmt = $conn->prepare('
        SELECT startzeit, endzeit, pause, standort, beschreibung
        FROM zeiterfassung
        WHERE user_id = ?
        ORDER BY startzeit ASC
    ');
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tpPdfDurationMinutes(array $record): ?int
{
    if (empty($record['startzeit']) || empty($record['endzeit'])) {
        return null;
    }

    $start = strtotime($record['startzeit']);
    $end = strtotime($record['endzeit']);
    if (!$start || !$end || $end <= $start) {
        return null;
    }

    return max(0, (int)floor(($end - $start) / 60) - (int)($record['pause'] ?? 0));
}

function tpPdfFormatMinutes($minutes): string
{
    if ($minutes === null) {
        return '-';
    }

    $sign = $minutes < 0 ? '-' : '';
    $minutes = abs((int)round($minutes));

    return $sign . sprintf('%02d:%02d', floor($minutes / 60), $minutes % 60);
}

function tpPdfUserSummary(array $user, array $records): array
{
    $dailyMinutes = [];
    $vacationDays = [];
    $sickDays = [];
    $totalMinutes = 0;
    $totalPause = 0;

    foreach ($records as $record) {
        $description = trim($record['beschreibung'] ?? '');
        $date = !empty($record['startzeit']) ? date('Y-m-d', strtotime($record['startzeit'])) : null;

        if ($date && $description === 'Urlaub') {
            $vacationDays[$date] = true;
        }

        if ($date && $description === 'Krank') {
            $sickDays[$date] = true;
        }

        if ($description === 'Feiertag') {
            continue;
        }

        $minutes = tpPdfDurationMinutes($record);
        if ($minutes === null) {
            continue;
        }

        $dailyMinutes[$date] = ($dailyMinutes[$date] ?? 0) + $minutes;
        $totalMinutes += $minutes;
        $totalPause += (int)($record['pause'] ?? 0);
    }

    $regularMinutes = (float)($user['regelarbeitszeit'] ?? 8) * 60;
    $overtimeMinutes = (float)($user['ueberstunden'] ?? 0) * 60;
    foreach ($dailyMinutes as $date => $minutes) {
        $weekday = (int)date('N', strtotime($date));
        $overtimeMinutes += $weekday >= 1 && $weekday <= 5 ? $minutes - $regularMinutes : $minutes;
    }

    return [
        'days' => count($dailyMinutes),
        'vacation_days' => count($vacationDays),
        'vacation_days_total' => (int)($user['vacation_days_per_year'] ?? 30),
        'vacation_days_remaining' => max(0, (int)($user['vacation_days_per_year'] ?? 30) - count($vacationDays)),
        'sick_days' => count($sickDays),
        'total_minutes' => $totalMinutes,
        'pause_minutes' => $totalPause,
        'overtime_minutes' => $overtimeMinutes,
    ];
}

class TimePointSimplePdf
{
    private array $pages = [];
    private string $content = '';
    private float $x = 36;
    private float $y = 806;
    private float $width = 595;
    private float $height = 842;
    private float $margin = 36;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $this->content = '';
        $this->x = $this->margin;
        $this->y = $this->height - $this->margin;
    }

    public function text(string $text, float $size = 10, ?float $x = null, ?float $y = null, array $color = [0, 0, 0]): void
    {
        $x = $x ?? $this->x;
        $y = $y ?? $this->y;
        $encoded = $this->escape($text);
        [$r, $g, $b] = $color;
        $this->content .= "{$r} {$g} {$b} rg\nBT /F1 {$size} Tf 1 0 0 1 {$x} {$y} Tm ({$encoded}) Tj ET\n0 0 0 rg\n";
    }

    public function line(string $text = '', float $size = 10, float $leading = 14, array $color = [0, 0, 0]): void
    {
        if ($this->y < $this->margin + 24) {
            $this->addPage();
        }
        if ($text !== '') {
            $this->text($text, $size, $this->x, $this->y, $color);
        }
        $this->y -= $leading;
    }

    public function hr(): void
    {
        $y = $this->y + 4;
        $x2 = $this->width - $this->margin;
        $this->content .= "{$this->margin} {$y} m {$x2} {$y} l S\n";
        $this->y -= 10;
    }

    public function sectionTitle(string $text): void
    {
        $this->y -= 4;
        $this->line($text, 12, 16, [0.08, 0.22, 0.42]);
        $this->hr();
    }

    public function metricRow(array $items): void
    {
        if ($this->y < $this->margin + 36) {
            $this->addPage();
        }

        $width = 170;
        $x = $this->margin;
        foreach ($items as $item) {
            $this->text($item[0], 8, $x, $this->y, [0.35, 0.35, 0.35]);
            $this->text($item[1], 12, $x, $this->y - 14, $item[2] ?? [0, 0, 0]);
            $x += $width;
        }
        $this->y -= 34;
    }

    public function tableRow(array $columns, array $widths, float $size = 8, float $leading = 13, array $color = [0, 0, 0]): void
    {
        if ($this->y < $this->margin + 24) {
            $this->addPage();
        }

        $x = $this->margin;
        foreach ($columns as $index => $column) {
            $this->text($this->clip((string)$column, (int)floor($widths[$index] / ($size * 0.45))), $size, $x, $this->y, $color);
            $x += $widths[$index];
        }
        $this->y -= $leading;
    }

    public function render(bool $pdfA = false): string
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
            $this->content = '';
        }

        $objects = [];
        $fontObjectId = 3;
        $pageStartId = 4;

        if ($pdfA) {
            $metadataId = 4;
            $iccProfileId = 5;
            $fontDescriptorId = 6;
            $fontFileId = 7;
            $pageStartId = 8;

            $fontFile = $this->readFirstExistingFile([
                'C:\\Windows\\Fonts\\arial.ttf',
                'C:\\Windows\\Fonts\\Arial.ttf',
            ]) ?: '';
            $iccProfile = $this->readFirstExistingFile([
                'C:\\Windows\\System32\\spool\\drivers\\color\\sRGB Color Space Profile.icm',
                'C:\\Windows\\System32\\spool\\drivers\\color\\sRGB Color Space Profile.icc',
            ]) ?: 'sRGB IEC61966-2.1';

            $objects[1] = "<< /Type /Catalog /Pages 2 0 R /Metadata {$metadataId} 0 R /OutputIntents [<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) /DestOutputProfile {$iccProfileId} 0 R >>] >>";
            $objects[$fontObjectId] = '<< /Type /Font /Subtype /TrueType /BaseFont /ArialMT /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 255 /Widths [' . $this->arialWinAnsiWidths() . "] /FontDescriptor {$fontDescriptorId} 0 R >>";
            $objects[$fontDescriptorId] = "<< /Type /FontDescriptor /FontName /ArialMT /Flags 32 /FontBBox [-665 -325 2028 1037] /ItalicAngle 0 /Ascent 905 /Descent -212 /CapHeight 716 /StemV 88 /AvgWidth 441 /MaxWidth 2665 /FontFile2 {$fontFileId} 0 R >>";
            $objects[$fontFileId] = "<< /Length " . strlen($fontFile) . " /Length1 " . strlen($fontFile) . " >>\nstream\n{$fontFile}\nendstream";
            $xmp = $this->buildPdfAXmp();
            $objects[$metadataId] = "<< /Type /Metadata /Subtype /XML /Length " . strlen($xmp) . " >>\nstream\n{$xmp}\nendstream";
            $objects[$iccProfileId] = "<< /N 3 /Length " . strlen($iccProfile) . " >>\nstream\n{$iccProfile}\nendstream";
        } else {
            $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
            $objects[$fontObjectId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        }

        $kids = [];
        $nextId = $pageStartId;
        foreach ($this->pages as $pageContent) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->width} {$this->height}] /Resources << /Font << /F1 {$fontObjectId} 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length " . strlen($pageContent) . " >>\nstream\n{$pageContent}endstream";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = $pdfA ? "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" : "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function escape(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function clip(string $text, int $maxLength): string
    {
        if ($maxLength <= 0 || strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, max(0, $maxLength - 3)) . '...';
    }

    private function readFirstExistingFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_readable($path)) {
                $content = file_get_contents($path);
                if ($content !== false && $content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    private function buildPdfAXmp(): string
    {
        $created = htmlspecialchars(date('c'), ENT_QUOTES, 'UTF-8');
        return <<<XML
<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about=""
      xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"
      xmlns:dc="http://purl.org/dc/elements/1.1/"
      xmlns:xmp="http://ns.adobe.com/xap/1.0/"
      xmlns:pdf="http://ns.adobe.com/pdf/1.3/">
      <pdfaid:part>1</pdfaid:part>
      <pdfaid:conformance>B</pdfaid:conformance>
      <dc:title>
        <rdf:Alt>
          <rdf:li xml:lang="x-default">TimePoint Arbeitszeitauswertung</rdf:li>
        </rdf:Alt>
      </dc:title>
      <xmp:CreatorTool>TimePoint</xmp:CreatorTool>
      <xmp:CreateDate>{$created}</xmp:CreateDate>
      <xmp:ModifyDate>{$created}</xmp:ModifyDate>
      <pdf:Producer>TimePoint SimplePdf PDF/A Export</pdf:Producer>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>
XML;
    }

    private function arialWinAnsiWidths(): string
    {
        $widths = [
            278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
            556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
            1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
            667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
            333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
            556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,0,
            556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,
            0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,
            278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,
            400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,
            667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,
            722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,
            556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,
            556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500,
        ];

        return implode(' ', $widths);
    }
}

function buildTimePointReportPdf(array $targetUserIds, bool $pdfA = false, bool $summaryOnly = false): string
{
    $pdf = new TimePointSimplePdf();
    $title = $summaryOnly ? 'Gesamtauswertung Mitarbeiter' : 'Arbeitszeitauswertung';
    $pdf->line('TimePoint - ' . $title, 16, 20);
    $pdf->line('Erstellt am: ' . date('d.m.Y H:i'), 9, 14);
    $pdf->hr();

    if ($summaryOnly) {
        $pdf->tableRow(['Mitarbeiter', 'Tage', 'Urlaub', 'Krank', 'Arbeitszeit', 'Überstunden'], [135, 45, 55, 55, 85, 85], 9);
        $pdf->hr();
    }

    foreach ($targetUserIds as $index => $targetUserId) {
        $user = tpPdfFetchUser((int)$targetUserId);
        if (!$user) {
            continue;
        }

        $records = tpPdfFetchRecords((int)$targetUserId);
        $summary = tpPdfUserSummary($user, $records);

        if ($summaryOnly) {
            $pdf->tableRow([
                $user['username'],
                $summary['days'],
                $summary['vacation_days'],
                $summary['sick_days'],
                tpPdfFormatMinutes($summary['total_minutes']),
                tpPdfFormatMinutes($summary['overtime_minutes']),
            ], [135, 45, 55, 55, 85, 85], 9);
            continue;
        }

        if ($index > 0) {
            $pdf->addPage();
        }

        $pdf->line('Mitarbeiter: ' . $user['username'], 14, 20, [0.08, 0.22, 0.42]);
        $pdf->line('Regelarbeitszeit: ' . (float)$user['regelarbeitszeit'] . ' h/Tag', 9, 16, [0.25, 0.25, 0.25]);
        $pdf->sectionTitle('Zusammenfassung');
        $pdf->metricRow([
            ['Arbeitszeit gesamt', tpPdfFormatMinutes($summary['total_minutes'])],
            ['Pausen gesamt', $summary['pause_minutes'] . ' min'],
            ['Überstunden', tpPdfFormatMinutes($summary['overtime_minutes']), $summary['overtime_minutes'] < 0 ? [0.75, 0.05, 0.05] : [0, 0.45, 0.16]],
        ]);
        $pdf->metricRow([
            ['Gearbeitete Tage', (string)$summary['days']],
            ['Krankheitstage', $summary['sick_days'] . ' Tage', [0.75, 0.05, 0.05]],
            ['Urlaub genommen', $summary['vacation_days'] . ' von ' . $summary['vacation_days_total'] . ' Tagen', [0, 0.45, 0.16]],
        ]);
        $pdf->metricRow([
            ['Resturlaub', $summary['vacation_days_remaining'] . ' Tage', [0, 0.45, 0.16]],
            ['Berichtsdatum', date('d.m.Y')],
            ['', ''],
        ]);
        $pdf->line('Überstunden: ' . tpPdfFormatMinutes($summary['overtime_minutes']), 10);
        $pdf->sectionTitle('Legende');
        $pdf->line('Urlaub = gruen    Krankheit = rot    Feiertag = blau', 9, 18);

        $currentMonthLabel = null;
        foreach ($records as $record) {
            $startTimestamp = strtotime($record['startzeit']);
            $endTimestamp = !empty($record['endzeit']) ? strtotime($record['endzeit']) : null;
            $description = trim($record['beschreibung'] ?? '');
            $rowColor = match ($description) {
                'Urlaub' => [0, 0.45, 0.16],
                'Krank' => [0.75, 0.05, 0.05],
                'Feiertag' => [0.05, 0.24, 0.75],
                default => [0, 0, 0],
            };

            $monthLabel = $startTimestamp ? date('m.Y', $startTimestamp) : 'Ohne Datum';
            if ($monthLabel !== $currentMonthLabel) {
                if ($currentMonthLabel !== null) {
                    $pdf->addPage();
                }

                $currentMonthLabel = $monthLabel;
                $pdf->sectionTitle('Zeitbuchungen ' . $currentMonthLabel);
                $pdf->tableRow(['Datum', 'Start', 'Ende', 'Pause', 'Dauer', 'Standort', 'Kommentar'], [65, 48, 48, 45, 50, 75, 170], 8);
                $pdf->hr();
            }

            $pdf->tableRow([
                $startTimestamp ? date('d.m.Y', $startTimestamp) : '-',
                $startTimestamp ? date('H:i', $startTimestamp) : '-',
                $endTimestamp ? date('H:i', $endTimestamp) : '-',
                (int)($record['pause'] ?? 0),
                tpPdfFormatMinutes(tpPdfDurationMinutes($record)),
                $record['standort'] ?? '',
                $record['beschreibung'] ?? '',
            ], [65, 48, 48, 45, 50, 75, 170], 8, 13, $rowColor);
        }
    }

    return $pdf->render($pdfA);
}

function buildTimePointReportFilename(?array $user = null, bool $pdfA = false, bool $summaryOnly = false): string
{
    if ($summaryOnly) {
        return 'Arbeitszeitauswertung_Gesamt_' . ($pdfA ? 'PDF-A_' : '') . date('Y-m-d') . '.pdf';
    }

    $name = $user ? preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$user['username']) : '';
    $name = trim((string)$name, '_');
    $prefix = $name !== '' ? 'Arbeitszeitauswertung_' . $name . '_' : 'Arbeitszeitauswertung_';

    return $prefix . ($pdfA ? 'PDF-A_' : '') . date('Y-m-d') . '.pdf';
}
