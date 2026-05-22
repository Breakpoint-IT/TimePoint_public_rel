<?php
$embedded = isset($_GET['embed']) && $_GET['embed'] === '1';

if (!$embedded) {
    include 'header.php';
} else {
    require_once 'config.php';
    $lang = $_SESSION['lang'] ?? 'de';
    $lang = in_array($lang, ['de', 'en'], true) ? $lang : 'de';
    require_once __DIR__ . "/languages/$lang.php";
}

$version = defined('TIMEPOINT_VERSION') ? TIMEPOINT_VERSION : '1.0.3';
$changelogPath = __DIR__ . '/CHANGELOG.md';
$changelogContent = is_readable($changelogPath) ? file_get_contents($changelogPath) : '';

function renderMarkdownInline(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code class="px-1 py-0.5 rounded bg-base-200">$1</code>', $text);
    return $text;
}

function renderReadmeMarkdown(string $markdown): string
{
    if (trim($markdown) === '') {
        return '<div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i><span>' . CHANGELOG_READ_ERROR . '</span></div>';
    }

    $html = '';
    $listStack = [];
    $lines = preg_split('/\R/', $markdown);

    $closeListsTo = static function (int $level) use (&$html, &$listStack): void {
        while (count($listStack) > $level) {
            $html .= '</ul>';
            array_pop($listStack);
        }
    };

    foreach ($lines as $line) {
        if (trim($line) === '') {
            $closeListsTo(0);
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
            $closeListsTo(0);
            $level = strlen($matches[1]);
            $classes = match ($level) {
                1 => 'text-3xl font-bold mt-2 mb-4',
                2 => 'text-2xl font-semibold mt-6 mb-3 border-b border-base-300 pb-2',
                3 => 'text-xl font-semibold mt-5 mb-2',
                default => 'text-lg font-semibold mt-4 mb-2',
            };
            $html .= '<h' . $level . ' class="' . $classes . '">' . renderMarkdownInline($matches[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^(\s*)-\s+(?:\[(x|X| )\]|\[\])?\s*(.*)$/', $line, $matches)) {
            $indent = strlen(str_replace("\t", '    ', $matches[1]));
            $level = (int)floor($indent / 4);
            while (count($listStack) <= $level) {
                $html .= '<ul class="list-none space-y-1 ml-' . (count($listStack) > 0 ? '5' : '0') . '">';
                $listStack[] = true;
            }
            $closeListsTo($level + 1);

            $state = $matches[2] ?? null;
            $text = $matches[3] ?? '';
            $icon = '';
            if ($state !== null) {
                $done = strtolower($state) === 'x';
                $icon = $done
                    ? '<i class="fas fa-check-square text-success mr-2"></i>'
                    : '<i class="far fa-square opacity-60 mr-2"></i>';
            }

            $html .= '<li class="leading-relaxed">' . $icon . '<span>' . renderMarkdownInline($text) . '</span></li>';
            continue;
        }

        $closeListsTo(0);
        $html .= '<p class="leading-relaxed">' . renderMarkdownInline($line) . '</p>';
    }

    $closeListsTo(0);
    return $html;
}
?>

<?php if ($embedded) : ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang ?? 'de') ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.9.4/dist/full.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="bg-base-100 text-base-content">
<?php endif; ?>

<div class="<?= $embedded ? 'p-4' : 'min-h-screen bg-base-200' ?>">
    <div class="<?= $embedded ? '' : 'container mx-auto px-4 py-8' ?>">
        <div class="card bg-base-100 <?= $embedded ? '' : 'shadow-xl' ?>">
            <div class="card-body">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                    <h1 class="card-title text-3xl">
                        <i class="fas fa-list-check text-primary"></i>
                        <?= CHANGELOG_TITLE ?>
                    </h1>
                    <div class="badge badge-primary badge-lg">Version <?= htmlspecialchars($version) ?></div>
                </div>

                <div class="prose max-w-none">
                    <?= renderReadmeMarkdown($changelogContent) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($embedded) : ?>
</body>
</html>
<?php endif; ?>
